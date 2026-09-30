<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\WaMessageService;
use App\Services\WaTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WaPaymentVerificationController extends Controller
{
    public function __construct(
        protected PaymentRepositoryInterface $paymentRepo,
        protected WaMessageService $messageService,
        protected WaTemplateRenderer $templateRenderer
    ) {}

    /**
     * Get paginated WhatsApp & manual transfer payments for admin verification.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only([
            'status',
            'method',
            'source',
            'is_duplicate_suspect',
            'invoice_id',
            'search',
        ]);

        // Default to filtering payments where method is manual_transfer or source is whatsapp/manual_admin
        if (empty($filters['source']) && empty($filters['method'])) {
            $filters['method'] = 'manual_transfer';
        }

        $perPage = (int) $request->input('per_page', 15);
        $payments = $this->paymentRepo->getPaginated($filters, $perPage);

        return PaymentResource::collection($payments);
    }

    /**
     * Get count of pending payments (specifically from WhatsApp) for badge displays.
     */
    public function pendingCount(): JsonResponse
    {
        $count = $this->paymentRepo->getPendingWaPaymentsCount();

        return response()->json([
            'success' => true,
            'count' => $count,
        ]);
    }

    /**
     * Show single payment verification detail.
     */
    public function show(string $id): JsonResponse
    {
        $payment = $this->paymentRepo->findById($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Data pembayaran tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new PaymentResource($payment),
        ]);
    }

    /**
     * Verify (Approve or Reject) a payment with automated WhatsApp notification.
     */
    public function verify(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string|max:500',
            'reject_reason' => 'required_if:action,reject|nullable|string|max:500',
        ], [
            'reject_reason.required_if' => 'Alasan penolakan wajib diisi jika menolak pembayaran.',
        ]);

        $payment = $this->paymentRepo->findById($id);
        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Data pembayaran tidak ditemukan.',
            ], 404);
        }

        if ($payment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Pembayaran ini sudah pernah diproses sebelumnya.',
            ], 422);
        }

        $action = (string) $validated['action'];
        $notes = $validated['notes'] ?? null;
        $rejectReason = $validated['reject_reason'] ?? null;
        $admin = $request->user();

        $result = DB::transaction(function () use ($payment, $action, $notes, $rejectReason, $admin) {
            $invoice = Invoice::query()->lockForUpdate()->find($payment->invoice_id);
            if (!$invoice) {
                throw new \RuntimeException('Invoice terkait tidak ditemukan.');
            }

            $tenantPhone = $invoice->tenancy?->tenant_phone;
            $tenantName = $invoice->tenancy?->tenant_name ?? 'Penghuni';
            $kamarNo = $invoice->tenancy?->room?->room_number ?? '-';
            $periode = $invoice->period ?? $invoice->invoice_number;

            if ($action === 'approve') {
                $payment->update([
                    'status' => 'success',
                    'verified_at' => now(),
                    'verified_by' => $admin->id,
                    'notes' => $notes ?: 'Pembayaran disetujui oleh admin.',
                ]);

                $newPaidAmount = (float) $invoice->paid_amount + (float) $payment->amount;
                $invoice->paid_amount = $newPaidAmount;
                $invoice->status = ($newPaidAmount >= (float) $invoice->total_amount) ? 'lunas' : 'sebagian_dibayar';
                $invoice->save();

                // Dispatch WhatsApp Notification: proof_approved
                if ($tenantPhone) {
                    $msgText = $this->templateRenderer->render('proof_approved', [
                        'nama' => $tenantName,
                        'kamar' => $kamarNo,
                        'periode' => $periode,
                        'nominal' => 'Rp ' . number_format((float) $payment->amount, 0, ',', '.'),
                    ]);

                    $this->messageService->send($tenantPhone, $msgText, [
                        'template_key' => 'proof_approved',
                        'related_type' => Payment::class,
                        'related_id' => $payment->id,
                        'force' => true,
                    ]);
                }
            } else {
                $payment->update([
                    'status' => 'failed',
                    'reject_reason' => $rejectReason,
                    'verified_at' => now(),
                    'verified_by' => $admin->id,
                    'notes' => $notes ?: 'Pembayaran ditolak oleh admin.',
                ]);

                // Restore invoice status
                $invoice->status = ((float) $invoice->paid_amount > 0) ? 'sebagian_dibayar' : 'belum_bayar';
                $invoice->save();

                // Dispatch WhatsApp Notification: proof_rejected
                if ($tenantPhone) {
                    $msgText = $this->templateRenderer->render('proof_rejected', [
                        'nama' => $tenantName,
                        'kamar' => $kamarNo,
                        'periode' => $periode,
                        'alasan_penolakan' => $rejectReason ?? 'Bukti tidak sesuai.',
                    ]);

                    $this->messageService->send($tenantPhone, $msgText, [
                        'template_key' => 'proof_rejected',
                        'related_type' => Payment::class,
                        'related_id' => $payment->id,
                        'force' => true,
                    ]);
                }
            }

            return $payment->fresh(['invoice.tenancy.room', 'verifier']);
        });

        return response()->json([
            'success' => true,
            'message' => $action === 'approve'
                ? 'Pembayaran berhasil disetujui dan invoice telah diperbarui.'
                : 'Pembayaran telah ditolak dan notifikasi telah dikirimkan ke penghuni.',
            'data' => new PaymentResource($result),
        ]);
    }

    /**
     * Record a manual payment directly by admin (source = 'manual_admin').
     */
    public function manualPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => 'required|uuid|exists:invoices,id',
            'amount' => 'required|numeric|min:1000',
            'proof_file' => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:5120',
            'notes' => 'nullable|string|max:500',
            'auto_approve' => 'nullable|boolean',
        ]);

        $invoice = Invoice::with(['tenancy.room'])->findOrFail($validated['invoice_id']);
        $amount = (float) $validated['amount'];
        $storagePath = null;
        $mime = null;
        $size = null;
        $sha256 = null;

        if ($request->hasFile('proof_file')) {
            $file = $request->file('proof_file');
            $ext = $file->getClientOriginalExtension() ?: 'jpg';
            $filename = 'proof_' . Str::uuid()->toString() . '.' . $ext;
            $storagePath = $file->storeAs('private/payment_proofs', $filename);
            $mime = $file->getClientMimeType();
            $size = $file->getSize();
            $sha256 = hash_file('sha256', $file->getRealPath()) ?: null;
        }

        $autoApprove = (bool) ($validated['auto_approve'] ?? true);
        $admin = $request->user();

        $payment = DB::transaction(function () use ($invoice, $amount, $storagePath, $mime, $size, $sha256, $validated, $autoApprove, $admin) {
            $payment = $this->paymentRepo->create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => 'manual_transfer',
                'source' => 'manual_admin',
                'proof_file' => $storagePath,
                'proof_mime' => $mime,
                'proof_size' => $size,
                'proof_sha256' => $sha256,
                'status' => $autoApprove ? 'success' : 'pending',
                'verified_at' => $autoApprove ? now() : null,
                'verified_by' => $autoApprove ? $admin->id : null,
                'notes' => $validated['notes'] ?? 'Dicatat manual oleh admin',
            ]);

            if ($autoApprove) {
                $newPaid = (float) $invoice->paid_amount + $amount;
                $invoice->paid_amount = $newPaid;
                $invoice->status = ($newPaid >= (float) $invoice->total_amount) ? 'lunas' : 'sebagian_dibayar';
                $invoice->save();
            }

            return $payment;
        });

        return response()->json([
            'success' => true,
            'message' => 'Pembayaran manual berhasil dicatat.',
            'data' => new PaymentResource($payment->load(['invoice.tenancy.room', 'verifier'])),
        ], 201);
    }
}
