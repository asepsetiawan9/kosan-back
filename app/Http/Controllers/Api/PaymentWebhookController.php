<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentGatewayService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected PaymentGatewayService $gatewayService
    ) {}

    /**
     * Handle webhook notification from payment gateway (Midtrans/Xendit).
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $payload = $request->all();
            $signatureHeader = $request->header('X-Callback-Signature')
                ?: $request->header('Signature')
                ?: (string) ($payload['signature_key'] ?? '');

            $result = $this->gatewayService->handleWebhook($provider, $payload, $signatureHeader ?: null);

            return response()->json([
                'success' => true,
                'message' => 'Webhook berhasil diproses.',
                'data' => $result,
            ], 200);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[WEBHOOK ERROR] ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem internal pada pemrosesan webhook.',
            ], 500);
        }
    }
}
