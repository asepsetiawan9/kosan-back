<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\ManualPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentRepositoryInterface $paymentRepository,
        protected ManualPaymentService $manualPaymentService
    ) {}

    /**
     * Get paginated payments list.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['status', 'method', 'invoice_id', 'search']);
        $perPage = (int) $request->input('per_page', 15);

        $payments = $this->paymentRepository->getPaginated($filters, $perPage);

        return PaymentResource::collection($payments);
    }

    /**
     * Get detailed payment record.
     */
    public function show(string $id): JsonResponse
    {
        $payment = $this->paymentRepository->findById($id);
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
     * Stream protected manual payment proof file via Temporary Signed URL.
     */
    public function streamProof(string $id): StreamedResponse|JsonResponse
    {
        $payment = Payment::find($id);

        if (!$payment || !$payment->proof_file || !Storage::exists($payment->proof_file)) {
            return response()->json([
                'success' => false,
                'message' => 'Berkas bukti pembayaran tidak ditemukan atau telah kedaluwarsa.',
            ], 404);
        }

        $mimeType = Storage::mimeType($payment->proof_file) ?: 'application/octet-stream';

        return Storage::response($payment->proof_file, null, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="proof-' . $payment->id . '"',
        ]);
    }

    /**
     * Verify (approve or reject) a pending manual payment.
     */
    public function verify(VerifyPaymentRequest $request, string $id): JsonResponse
    {
        $payment = $this->paymentRepository->findById($id);
        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Data pembayaran tidak ditemukan.',
            ], 404);
        }

        try {
            $verifiedPayment = $this->manualPaymentService->verifyPayment(
                $payment,
                $request->validated('action'),
                $request->validated('notes'),
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => $request->validated('action') === 'approve'
                    ? 'Pembayaran berhasil diverifikasi dan saldo tagihan telah diperbarui.'
                    : 'Pembayaran transfer telah ditolak.',
                'data' => new PaymentResource($verifiedPayment),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
