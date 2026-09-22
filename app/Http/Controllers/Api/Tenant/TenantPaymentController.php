<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManualPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\ManualPaymentService;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;

class TenantPaymentController extends Controller
{
    public function __construct(
        protected PaymentGatewayService $gatewayService,
        protected ManualPaymentService $manualService,
        protected PaymentRepositoryInterface $paymentRepository
    ) {}

    /**
     * Initiate online payment gateway transaction for an invoice.
     */
    public function pay(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::with(['tenancy.room', 'tenancy.user'])->findOrFail($id);
        $this->authorize('view', $invoice);

        $request->validate([
            'amount' => ['nullable', 'numeric', 'min:1000'],
            'provider' => ['nullable', 'string', 'in:midtrans,xendit'],
        ]);

        $amount = $request->input('amount') ? (float) $request->input('amount') : null;
        $provider = $request->input('provider', 'midtrans');

        try {
            $transactionData = $this->gatewayService->createTransaction($invoice, $amount, $provider);

            return response()->json([
                'success' => true,
                'message' => 'Token transaksi pembayaran berhasil diinisialisasi.',
                'data' => $transactionData,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Submit manual bank transfer payment proof.
     */
    public function manualPay(ManualPaymentRequest $request, string $id): JsonResponse
    {
        $invoice = Invoice::with(['tenancy.room', 'tenancy.user'])->findOrFail($id);
        $this->authorize('view', $invoice);

        try {
            $payment = $this->manualService->submitManualPayment(
                $invoice,
                (float) $request->validated('amount'),
                $request->file('proof_file'),
                $request->validated('notes')
            );

            return response()->json([
                'success' => true,
                'message' => 'Bukti pembayaran berhasil dikirim. Mohon menunggu verifikasi dari admin pengelola.',
                'data' => new PaymentResource($payment),
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get payment history for an invoice.
     */
    public function payments(string $id): AnonymousResourceCollection
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorize('view', $invoice);

        $payments = $this->paymentRepository->getByInvoiceId($invoice->id);

        return PaymentResource::collection($payments);
    }
}
