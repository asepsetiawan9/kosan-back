<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TestSendWaRequest;
use App\Http\Resources\WaMessageResource;
use App\Http\Resources\WaTemplateResource;
use App\Repositories\Contracts\WaMessageRepositoryInterface;
use App\Repositories\Contracts\WaTemplateRepositoryInterface;
use App\Services\WaMessageService;
use App\Services\WaTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaAdminController extends Controller
{
    /**
     * Send a test WhatsApp message directly from the admin dashboard.
     */
    public function testSend(
        TestSendWaRequest $request,
        WaMessageService $service,
        WaTemplateRenderer $renderer
    ): JsonResponse {
        $phone = (string) $request->input('phone');
        $templateKey = $request->input('template_key');

        if (!empty($templateKey)) {
            $params = (array) $request->input('template_params', []);
            $body = $renderer->render($templateKey, $params);
        } else {
            $body = (string) $request->input('message');
        }

        $message = $service->send($phone, $body, [
            'template_key' => $templateKey,
            'related_type' => 'test_send',
            'force' => true, // Admin test send ignores opt-out
        ]);

        return response()->json([
            'message' => 'Pesan uji WhatsApp berhasil dimasukkan ke antrean pengiriman.',
            'data' => new WaMessageResource($message),
        ], 200);
    }

    /**
     * Check current WhatsApp provider connection status and statistics.
     */
    public function connectionStatus(WaMessageService $service): JsonResponse
    {
        $status = $service->getConnectionStatus();

        return response()->json([
            'data' => $status,
        ], 200);
    }

    /**
     * Get comprehensive WhatsApp subsystem health diagnostics.
     */
    public function health(\App\Services\WaHealthCheckService $healthService): JsonResponse
    {
        $health = $healthService->checkHealth();

        return response()->json([
            'data' => $health,
        ], 200);
    }

    /**
     * Get paginated WhatsApp message logs with search and filtering.
     */
    public function messages(Request $request, WaMessageRepositoryInterface $repo): JsonResponse
    {
        $filters = [
            'status' => $request->query('status'),
            'direction' => $request->query('direction'),
            'phone' => $request->query('phone'),
            'search' => $request->query('search'),
        ];

        $perPage = (int) $request->query('per_page', 15);
        $paginated = $repo->getPaginated($filters, $perPage);

        return WaMessageResource::collection($paginated)->response();
    }

    /**
     * Re-queue a failed WhatsApp message.
     */
    public function resend(
        string $id,
        WaMessageService $service,
        WaMessageRepositoryInterface $repo
    ): JsonResponse {
        $message = $repo->findById($id);

        if (!$message) {
            return response()->json([
                'message' => 'Pesan WhatsApp tidak ditemukan.',
            ], 404);
        }

        $updated = $service->resend($message);

        return response()->json([
            'message' => 'Pesan WhatsApp berhasil dijadwalkan ulang untuk dikirim.',
            'data' => new WaMessageResource($updated),
        ], 200);
    }

    /**
     * List all available WhatsApp message templates and placeholders.
     */
    public function templates(
        WaTemplateRepositoryInterface $repo,
        WaTemplateRenderer $renderer
    ): JsonResponse {
        $templates = $repo->getAll();

        return response()->json([
            'data' => WaTemplateResource::collection($templates),
            'placeholders' => $renderer->getSupportedPlaceholders(),
        ], 200);
    }

    /**
     * Manually reset the anti-ban circuit breaker.
     */
    public function resetCircuitBreaker(WaMessageService $service): JsonResponse
    {
        $service->resetCircuitBreaker();

        return response()->json([
            'message' => 'Anti-ban circuit breaker berhasil di-reset.',
            'data' => $service->getConnectionStatus()['antiban'],
        ], 200);
    }
}
