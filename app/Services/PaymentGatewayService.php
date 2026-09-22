<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Invoice;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentGatewayService
{
    public function __construct(
        protected PaymentRepositoryInterface $paymentRepository
    ) {}

    /**
     * Create gateway transaction and generate snap token / redirect URL.
     *
     * @return array<string, mixed>
     */
    public function createTransaction(Invoice $invoice, ?float $amount = null, string $provider = 'midtrans'): array
    {
        $remaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;
        if ($remaining <= 0 || $invoice->status === 'lunas') {
            throw new InvalidArgumentException('Tagihan ini sudah lunas sepenuhnya.');
        }

        $payableAmount = ($amount !== null && $amount > 0 && $amount <= $remaining)
            ? $amount
            : $remaining;

        $orderId = 'PAY-' . strtoupper(Str::random(6)) . '-' . time();

        $payment = $this->paymentRepository->create([
            'invoice_id' => $invoice->id,
            'amount' => $payableAmount,
            'method' => 'gateway',
            'gateway_provider' => $provider,
            'gateway_transaction_id' => $orderId,
            'status' => 'pending',
        ]);

        $serverKey = (string) config('services.midtrans.server_key', '');
        $isProduction = (bool) config('services.midtrans.is_production', false);

        $clientKey = (string) config('services.midtrans.client_key', 'SB-Mid-client-TESTKEY123456789');
        $snapToken = 'snap_' . md5($orderId . $serverKey);
        $redirectUrl = 'https://app.sandbox.midtrans.com/snap/v2/vtweb/' . $snapToken;

        // Try calling real Midtrans API if real credentials provided and not test dummy
        if ($serverKey && !str_contains($serverKey, 'TESTKEY') && class_exists(Http::class)) {
            try {
                $endpoint = $isProduction
                    ? 'https://app.midtrans.com/snap/v1/transactions'
                    : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

                $response = Http::withBasicAuth($serverKey, '')
                    ->timeout(10)
                    ->post($endpoint, [
                        'transaction_details' => [
                            'order_id' => $orderId,
                            'gross_amount' => (int) $payableAmount,
                        ],
                        'customer_details' => [
                            'first_name' => $invoice->tenancy?->tenant_name ?? 'Penghuni',
                            'email' => $invoice->tenancy?->tenant_email ?? 'penghuni@kos.local',
                            'phone' => $invoice->tenancy?->tenant_phone ?? '081234567890',
                        ],
                    ]);

                if ($response->successful()) {
                    $snapData = $response->json();
                    $snapToken = $snapData['token'] ?? $snapToken;
                    $redirectUrl = $snapData['redirect_url'] ?? $redirectUrl;
                }
            } catch (\Throwable $e) {
                Log::warning('Midtrans API direct connection failed, falling back to local simulation: ' . $e->getMessage());
            }
        }

        return [
            'payment_id' => $payment->id,
            'order_id' => $orderId,
            'gross_amount' => $payableAmount,
            'remaining_amount' => $remaining,
            'provider' => $provider,
            'client_key' => $clientKey,
            'snap_token' => $snapToken,
            'redirect_url' => $redirectUrl,
            'invoice_number' => $invoice->invoice_number,
        ];
    }

    /**
     * Handle incoming gateway webhook callback idempotently.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handleWebhook(string $provider, array $payload, ?string $rawSignature = null): array
    {
        Log::info("[WEBHOOK RECEIVED] Provider: {$provider}", ['payload' => $payload]);

        $orderId = (string) ($payload['order_id'] ?? $payload['external_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '200');
        $grossAmount = (string) ($payload['gross_amount'] ?? '0');
        $transactionStatus = (string) ($payload['transaction_status'] ?? $payload['status'] ?? 'settlement');
        $providedSignature = $rawSignature ?? (string) ($payload['signature_key'] ?? '');

        if (!$orderId) {
            throw new InvalidArgumentException('Order ID tidak ditemukan pada payload webhook.');
        }

        $serverKey = (string) config('services.midtrans.server_key', '');

        // Verify signature if provided and serverKey configured
        if ($provider === 'midtrans' && $providedSignature && $serverKey) {
            $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
            if (!hash_equals($expectedSignature, $providedSignature)) {
                Log::warning('[WEBHOOK FORBIDDEN] Invalid Midtrans signature key', [
                    'order_id' => $orderId,
                    'provided' => $providedSignature,
                    'expected' => $expectedSignature,
                ]);
                throw new AuthorizationException('Signature webhook tidak valid.');
            }
        }

        $payment = $this->paymentRepository->findByGatewayTransactionId($provider, $orderId);

        if (!$payment) {
            // Check if transaction exists under id directly
            $payment = $this->paymentRepository->findById($orderId);
            if (!$payment) {
                Log::warning("[WEBHOOK NOT FOUND] Payment record with order {$orderId} not found.");
                return [
                    'status' => 'ignored',
                    'message' => 'Transaksi tidak ditemukan dalam sistem.',
                ];
            }
        }

        // Idempotency: If payment is already success, return immediately with 200 OK
        if ($payment->status === 'success') {
            Log::info("[WEBHOOK IDEMPOTENT] Payment {$payment->id} already processed as success.");
            return [
                'status' => 'already_processed',
                'payment_id' => $payment->id,
                'invoice_status' => $payment->invoice?->status ?? 'lunas',
            ];
        }

        // Process successful transaction statuses
        $successStatuses = ['capture', 'settlement', 'paid', 'success'];
        $failedStatuses = ['deny', 'cancel', 'expire', 'failed'];

        if (in_array($transactionStatus, $successStatuses, true)) {
            DB::transaction(function () use ($payment) {
                $payment->update([
                    'status' => 'success',
                    'verified_at' => now(),
                ]);

                $invoice = Invoice::query()->lockForUpdate()->find($payment->invoice_id);
                if ($invoice) {
                    $newPaidAmount = (float) $invoice->paid_amount + (float) $payment->amount;
                    $invoice->paid_amount = $newPaidAmount;

                    if ($newPaidAmount >= (float) $invoice->total_amount) {
                        $invoice->status = 'lunas';
                    } else {
                        $invoice->status = 'sebagian_dibayar';
                    }

                    $invoice->save();

                    // Dispatch notification to tenant
                    $tenantPhone = $invoice->tenancy?->tenant_phone;
                    if ($tenantPhone) {
                        $msg = "Halo {$invoice->tenancy?->tenant_name}, pembayaran tagihan {$invoice->invoice_number} sebesar Rp " .
                            number_format((float) $payment->amount, 0, ',', '.') .
                            " via Payment Gateway telah BERHASIL diverifikasi. Status tagihan saat ini: " . strtoupper($invoice->status) . ". Terima kasih!";
                        SendWhatsAppNotificationJob::dispatch($tenantPhone, $msg, 'payment_success');
                    }
                }
            });

            return [
                'status' => 'success',
                'payment_id' => $payment->id,
                'invoice_status' => $payment->invoice?->fresh()?->status,
            ];
        }

        if (in_array($transactionStatus, $failedStatuses, true)) {
            $payment->update([
                'status' => 'failed',
                'verified_at' => now(),
            ]);

            return [
                'status' => 'failed',
                'payment_id' => $payment->id,
            ];
        }

        return [
            'status' => 'pending',
            'payment_id' => $payment->id,
        ];
    }
}
