<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\WhatsAppProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\WaMessage;
use App\Repositories\Contracts\WaMessageRepositoryInterface;
use App\Services\WaChatbotService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        protected WhatsAppProviderInterface $provider,
        protected WaMessageRepositoryInterface $messageRepo,
        protected WaChatbotService $chatbotService
    ) {}

    /**
     * Webhook receiver endpoint for incoming WhatsApp messages and media.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        // 1. Authenticate webhook request
        if (!$this->provider->verifyWebhook($payload)) {
            Log::warning('[WhatsAppWebhook] Unauthorized webhook call attempt', [
                'ip' => $request->ip(),
                'payload' => $payload,
            ]);
            return response()->json(['error' => 'Unauthorized webhook token or signature.'], 401);
        }

        // 2. Parse payload into standardized IncomingMessage DTO
        $msg = $this->provider->parseIncomingWebhook($payload);
        if (!$msg) {
            return response()->json(['status' => 'ignored', 'message' => 'Non-message event.'], 200);
        }

        $providerName = (string) config('services.whatsapp.provider', 'fonnte');

        // 3. Deduplication check (Idempotency)
        $alreadyExists = WaMessage::where('provider', $providerName)
            ->where('direction', 'in')
            ->where('provider_message_id', $msg->providerMessageId)
            ->exists();

        if ($alreadyExists) {
            Log::info("[WhatsAppWebhook] Duplicate webhook ignored for ID: {$msg->providerMessageId}");
            return response()->json(['status' => 'already_processed'], 200);
        }

        // 4. Save incoming message log
        try {
            $waMessage = $this->messageRepo->create([
                'direction' => 'in',
                'tenant_id' => null,
                'phone' => $msg->from,
                'provider' => $providerName,
                'provider_message_id' => $msg->providerMessageId,
                'type' => $msg->type,
                'body' => $msg->text,
                'media_path' => $msg->media['url'] ?? null,
                'status' => 'received',
                'raw_payload' => $msg->raw,
            ]);
        } catch (Exception $e) {
            Log::error('[WhatsAppWebhook] Failed to insert incoming wa_message: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
        }

        // 5. Process through rule-based chatbot state machine
        try {
            $this->chatbotService->handleIncomingMessage($msg, $waMessage);
        } catch (Exception $e) {
            Log::error('[WhatsAppWebhook] Exception in chatbot state machine: ' . $e->getMessage(), [
                'wa_message_id' => $waMessage->id,
            ]);
            $waMessage->update([
                'status' => 'failed',
                'error_message' => 'Chatbot error: ' . $e->getMessage(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message_id' => $waMessage->id,
        ], 200);
    }
}
