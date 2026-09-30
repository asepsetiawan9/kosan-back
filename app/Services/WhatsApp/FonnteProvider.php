<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProviderInterface;
use App\Services\WhatsApp\DTO\IncomingMessage;
use App\Services\WhatsApp\DTO\MediaDownloadResult;
use App\Services\WhatsApp\DTO\SendResult;
use App\Support\PhoneNumber;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FonnteProvider implements WhatsAppProviderInterface
{
    protected string $token;
    protected string $baseUrl;

    public function __construct()
    {
        $this->token = (string) config('services.whatsapp.fonnte_token', '');
        $this->baseUrl = 'https://api.fonnte.com';
    }

    public function sendText(string $to, string $text, array $opts = []): SendResult
    {
        $normalized = PhoneNumber::normalize($to);

        if (empty($this->token)) {
            Log::error('[FonnteProvider] WA_FONNTE_TOKEN is not configured.');
            return SendResult::failed('WA_FONNTE_TOKEN belum dikonfigurasi di environment server.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
            ])->asForm()->post("{$this->baseUrl}/send", [
                'target' => $normalized,
                'message' => $text,
                'countryCode' => '62',
                ...$opts,
            ]);

            $json = $response->json();

            if (!$response->successful() || (isset($json['status']) && $json['status'] === false)) {
                $reason = $json['reason'] ?? $json['message'] ?? 'Fonnte API responded with error: ' . $response->body();
                Log::error("[FonnteProvider] Failed to send message to {$normalized}: {$reason}");
                return SendResult::failed($reason, $json ?? []);
            }

            // Fonnte returns id as array or string: ['id' => ['msg-id-123']] or string
            $msgId = 'fonnte-' . now()->timestamp;
            if (isset($json['id'])) {
                $msgId = is_array($json['id']) ? (string) ($json['id'][0] ?? $msgId) : (string) $json['id'];
            }

            return SendResult::success($msgId, $json ?? []);
        } catch (Exception $e) {
            Log::error("[FonnteProvider] Exception while sending message to {$normalized}: " . $e->getMessage());
            return SendResult::failed($e->getMessage());
        }
    }

    public function sendImage(string $to, string $imageUrl, ?string $caption = null): SendResult
    {
        $normalized = PhoneNumber::normalize($to);

        if (empty($this->token)) {
            Log::error('[FonnteProvider] WA_FONNTE_TOKEN is not configured.');
            return SendResult::failed('WA_FONNTE_TOKEN belum dikonfigurasi.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
            ])->asForm()->post("{$this->baseUrl}/send", [
                'target' => $normalized,
                'url' => $imageUrl,
                'caption' => $caption ?? '',
                'countryCode' => '62',
            ]);

            $json = $response->json();

            if (!$response->successful() || (isset($json['status']) && $json['status'] === false)) {
                $reason = $json['reason'] ?? $json['message'] ?? 'Fonnte API responded with error: ' . $response->body();
                Log::error("[FonnteProvider] Failed to send image to {$normalized}: {$reason}");
                return SendResult::failed($reason, $json ?? []);
            }

            $msgId = 'fonnte-img-' . now()->timestamp;
            if (isset($json['id'])) {
                $msgId = is_array($json['id']) ? (string) ($json['id'][0] ?? $msgId) : (string) $json['id'];
            }

            return SendResult::success($msgId, $json ?? []);
        } catch (Exception $e) {
            Log::error("[FonnteProvider] Exception while sending image to {$normalized}: " . $e->getMessage());
            return SendResult::failed($e->getMessage());
        }
    }

    public function parseIncomingWebhook(array $rawRequest): ?IncomingMessage
    {
        // Fonnte webhook payload format:
        // 'sender' => '628123456789', 'message' => 'text...', 'id' => '123', 'url' => 'http.../img.jpg', 'filename' => '...'
        $sender = $rawRequest['sender'] ?? null;
        if (!$sender) {
            return null;
        }

        $normalizedFrom = PhoneNumber::normalize((string) $sender);
        $messageId = (string) ($rawRequest['id'] ?? ('fonnte-in-' . now()->timestamp));
        $text = isset($rawRequest['message']) ? (string) $rawRequest['message'] : null;

        $media = null;
        $type = 'text';

        if (!empty($rawRequest['url'])) {
            $type = 'image';
            $ext = strtolower((string) ($rawRequest['extension'] ?? pathinfo((string) $rawRequest['url'], PATHINFO_EXTENSION)));
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
                default => 'application/octet-stream',
            };

            $media = [
                'url' => $rawRequest['url'],
                'mime' => $mime,
                'filename' => $rawRequest['filename'] ?? ("proof_{$messageId}.{$ext}"),
            ];
        }

        return new IncomingMessage(
            providerMessageId: $messageId,
            from: $normalizedFrom,
            type: $type,
            text: $text,
            media: $media,
            timestamp: now(),
            raw: $rawRequest
        );
    }

    public function downloadMedia(IncomingMessage $msg): MediaDownloadResult
    {
        if (empty($msg->media['url'])) {
            throw new Exception('No media URL provided in IncomingMessage.');
        }

        $response = Http::timeout(30)->get($msg->media['url']);
        if (!$response->successful()) {
            throw new Exception("Failed to download media from Fonnte: HTTP {$response->status()}");
        }

        $content = $response->body();
        $mime = $response->header('Content-Type') ?: ($msg->media['mime'] ?? 'image/jpeg');

        return new MediaDownloadResult(
            content: $content,
            mimeType: $mime,
            filename: $msg->media['filename'] ?? 'downloaded_proof.jpg',
            fileSize: strlen($content)
        );
    }

    public function verifyWebhook(array $rawRequest): bool
    {
        $secret = config('services.whatsapp.webhook_secret');
        if (empty($secret)) {
            return true;
        }

        // Fonnte can include token in headers or payload
        $token = request()->header('X-Webhook-Secret') ?? request()->header('Authorization') ?? ($rawRequest['token'] ?? null);

        return $token === $secret;
    }

    public function checkConnection(): array
    {
        if (empty($this->token)) {
            return [
                'status' => 'disconnected',
                'provider' => 'fonnte',
                'device' => null,
                'phone' => null,
                'message' => 'Token Fonnte belum diset (WA_FONNTE_TOKEN).',
                'is_configured' => false,
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
            ])->asForm()->post("{$this->baseUrl}/device");

            $json = $response->json();

            if ($response->successful() && isset($json['status']) && $json['status'] === true) {
                $isConnect = ($json['device_status'] ?? '') === 'connect';
                return [
                    'status' => $isConnect ? 'connected' : 'disconnected',
                    'provider' => 'fonnte',
                    'device' => $json['device'] ?? 'Fonnte Device',
                    'phone' => $json['device'] ?? null,
                    'device_status' => $json['device_status'] ?? 'unknown',
                    'quota' => $json['quota'] ?? 'N/A',
                    'expired' => $json['expired'] ?? null,
                    'message' => $isConnect ? 'Koneksi ke gateway WhatsApp aktif' : 'Perangkat WhatsApp belum terhubung (silakan scan QR di md.fonnte.com)',
                    'is_configured' => true,
                ];
            }

            return [
                'status' => 'disconnected',
                'provider' => 'fonnte',
                'message' => $json['reason'] ?? $json['message'] ?? 'Tidak dapat terhubung ke Fonnte.',
                'is_configured' => true,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'provider' => 'fonnte',
                'message' => $e->getMessage(),
                'is_configured' => true,
            ];
        }
    }
}
