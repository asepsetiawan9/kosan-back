<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ManualPaymentService
{
    public function __construct(
        protected PaymentRepositoryInterface $paymentRepository
    ) {}

    /**
     * Submit a manual bank transfer payment proof by tenant.
     */
    public function submitManualPayment(
        Invoice $invoice,
        float $amount,
        UploadedFile $proofFile,
        ?string $notes = null
    ): Payment {
        $remaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;
        if ($remaining <= 0 || $invoice->status === 'lunas') {
            throw new InvalidArgumentException('Tagihan ini sudah lunas sepenuhnya.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal pembayaran harus lebih besar dari 0.');
        }

        // Generate safe randomized filename for sensitive financial proof
        $extension = $proofFile->getClientOriginalExtension() ?: 'jpg';
        $filename = 'proof_' . (string) Str::uuid() . '.' . $extension;
        $path = $proofFile->storeAs('private/payment_proofs', $filename);

        return DB::transaction(function () use ($invoice, $amount, $path, $notes) {
            $payment = $this->paymentRepository->create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => 'manual_transfer',
                'proof_file' => $path,
                'status' => 'pending',
                'notes' => $notes,
            ]);

            // Mark invoice status as waiting verification
            $invoice->update([
                'status' => 'menunggu_verifikasi',
            ]);

            // Dispatch notification to admin WhatsApp
            $adminPhone = config('app.admin_whatsapp', '081234567890');
            $tenantName = $invoice->tenancy?->tenant_name ?? 'Penghuni';
            $msg = "Halo Admin, penghuni {$tenantName} telah mengunggah bukti transfer manual untuk tagihan {$invoice->invoice_number} sebesar Rp " .
                number_format($amount, 0, ',', '.') .
                ". Silakan periksa dan verifikasi melalui dashboard admin.";
            SendWhatsAppNotificationJob::dispatch($adminPhone, $msg, 'manual_payment_submitted');

            return $payment;
        });
    }

    /**
     * Verify (approve or reject) a manual payment by admin.
     */
    public function verifyPayment(
        Payment $payment,
        string $action,
        ?string $notes,
        User $admin
    ): Payment {
        if ($payment->status !== 'pending') {
            throw new InvalidArgumentException('Pembayaran ini sudah pernah diproses sebelumnya.');
        }

        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException("Aksi tidak valid. Hanya 'approve' atau 'reject'.");
        }

        return DB::transaction(function () use ($payment, $action, $notes, $admin) {
            $invoice = Invoice::query()->lockForUpdate()->find($payment->invoice_id);
            if (!$invoice) {
                throw new InvalidArgumentException('Invoice terkait tidak ditemukan.');
            }

            $tenantPhone = $invoice->tenancy?->tenant_phone;
            $tenantName = $invoice->tenancy?->tenant_name ?? 'Penghuni';

            if ($action === 'approve') {
                $payment->update([
                    'status' => 'success',
                    'verified_at' => now(),
                    'verified_by' => $admin->id,
                    'notes' => $notes ?: 'Disetujui oleh admin.',
                ]);

                $newPaidAmount = (float) $invoice->paid_amount + (float) $payment->amount;
                $invoice->paid_amount = $newPaidAmount;

                if ($newPaidAmount >= (float) $invoice->total_amount) {
                    $invoice->status = 'lunas';
                } else {
                    $invoice->status = 'sebagian_dibayar';
                }

                $invoice->save();

                if ($tenantPhone) {
                    $msg = "Halo {$tenantName}, pembayaran transfer Anda untuk tagihan {$invoice->invoice_number} sebesar Rp " .
                        number_format((float) $payment->amount, 0, ',', '.') .
                        " telah DISETUJUI oleh pengelola. Status tagihan: " . strtoupper($invoice->status) . ". Terima kasih!";
                    SendWhatsAppNotificationJob::dispatch($tenantPhone, $msg, 'payment_verified');
                }
            } else {
                // Reject payment
                $payment->update([
                    'status' => 'failed',
                    'verified_at' => now(),
                    'verified_by' => $admin->id,
                    'notes' => $notes ?: 'Ditolak oleh admin.',
                ]);

                // Restore invoice status based on previous paid amount
                if ((float) $invoice->paid_amount > 0) {
                    $invoice->status = 'sebagian_dibayar';
                } else {
                    $invoice->status = 'belum_bayar';
                }
                $invoice->save();

                if ($tenantPhone) {
                    $reason = $notes ? " Alasan: {$notes}" : '';
                    $msg = "Halo {$tenantName}, konfirmasi pembayaran transfer untuk tagihan {$invoice->invoice_number} DITOLAK oleh pengelola.{$reason} Silakan unggah bukti transfer yang valid.";
                    SendWhatsAppNotificationJob::dispatch($tenantPhone, $msg, 'payment_rejected');
                }
            }

            return $payment->fresh(['invoice', 'verifier']);
        });
    }

    /**
     * Generate temporary signed URL (5 minutes) for admin to stream sensitive proof file safely.
     */
    public function generateSignedProofUrl(Payment $payment): ?string
    {
        if (!$payment->proof_file || !Storage::exists($payment->proof_file)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'admin.payments.proof.stream',
            now()->addMinutes(5),
            ['id' => $payment->id]
        );
    }
}
