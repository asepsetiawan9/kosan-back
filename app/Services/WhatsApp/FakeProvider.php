<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProviderInterface;
use App\Services\WhatsApp\DTO\IncomingMessage;
use App\Services\WhatsApp\DTO\MediaDownloadResult;
use App\Services\WhatsApp\DTO\SendResult;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FakeProvider implements WhatsAppProviderInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected static array $sentMessages = [];

    protected static bool $shouldFailNext = false;
    protected static ?string $failureReason = null;

    public function sendText(string $to, string $text, array $opts = []): SendResult
    {
        $normalized = PhoneNumber::normalize($to);

        if (self::$shouldFailNext) {
            self::$shouldFailNext = false;
            $reason = self::$failureReason ?? 'Simulated FakeProvider delivery failure';
            Log::warning("[FakeWhatsAppProvider] Simulated failure sending to {$normalized}: {$reason}");

            return SendResult::failed($reason);
        }

        $id = 'fake-msg-' . Str::uuid()->toString();

        $record = [
            'id' => $id,
            'to' => $normalized,
            'text' => $text,
            'type' => 'text',
            'opts' => $opts,
            'sent_at' => now()->toIso8601String(),
        ];

        self::$sentMessages[] = $record;

        Log::info("[FakeWhatsAppProvider] Message recorded to {$normalized}: {$text}", [
            'id' => $id,
        ]);

        return SendResult::success($id, $record);
    }

    public function sendImage(string $to, string $imageUrl, ?string $caption = null): SendResult
    {
        $normalized = PhoneNumber::normalize($to);

        if (self::$shouldFailNext) {
            self::$shouldFailNext = false;
            $reason = self::$failureReason ?? 'Simulated FakeProvider image delivery failure';
            return SendResult::failed($reason);
        }

        $id = 'fake-img-' . Str::uuid()->toString();

        $record = [
            'id' => $id,
            'to' => $normalized,
            'imageUrl' => $imageUrl,
            'caption' => $caption,
            'type' => 'image',
            'sent_at' => now()->toIso8601String(),
        ];

        self::$sentMessages[] = $record;

        Log::info("[FakeWhatsAppProvider] Image recorded to {$normalized}: {$imageUrl} (Caption: {$caption})", [
            'id' => $id,
        ]);

        return SendResult::success($id, $record);
    }

    public function parseIncomingWebhook(array $rawRequest): ?IncomingMessage
    {
        $sender = $rawRequest['sender'] ?? $rawRequest['from'] ?? null;
        if (!$sender) {
            return null;
        }

        $normalizedFrom = PhoneNumber::normalize((string) $sender);
        $messageId = (string) ($rawRequest['id'] ?? $rawRequest['message_id'] ?? ('fake-in-' . Str::uuid()->toString()));
        $type = (string) ($rawRequest['type'] ?? 'text');
        $text = isset($rawRequest['message']) ? (string) $rawRequest['message'] : ($rawRequest['text'] ?? null);

        $media = null;
        if (isset($rawRequest['url'])) {
            $media = [
                'url' => $rawRequest['url'],
                'mime' => $rawRequest['mime'] ?? 'image/jpeg',
                'filename' => $rawRequest['filename'] ?? 'proof.jpg',
            ];
            $type = 'image';
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
        $dummyImage = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        return new MediaDownloadResult(
            content: $dummyImage !== false ? $dummyImage : '',
            mimeType: $msg->media['mime'] ?? 'image/png',
            filename: $msg->media['filename'] ?? 'fake_media.png',
            fileSize: strlen($dummyImage !== false ? $dummyImage : '')
        );
    }

    public function verifyWebhook(array $rawRequest): bool
    {
        $secret = config('services.whatsapp.webhook_secret');
        if (empty($secret)) {
            return true;
        }

        $token = request()->header('X-Webhook-Secret') ?? request()->header('Authorization') ?? ($rawRequest['token'] ?? $rawRequest['secret'] ?? null);
        return $token === $secret;
    }

    public function checkConnection(): array
    {
        return [
            'status' => 'connected',
            'provider' => 'fake',
            'device' => 'Simulated Fake WhatsApp Gateway',
            'phone' => '6281200000000',
            'quota' => 'Unlimited (Test Environment)',
            'is_configured' => true,
        ];
    }

    // --- Helper methods for tests ---

    public static function getSentMessages(): array
    {
        return self::$sentMessages;
    }

    public static function getLastSentMessage(): ?array
    {
        return empty(self::$sentMessages) ? null : self::$sentMessages[count(self::$sentMessages) - 1];
    }

    public static function clearSentMessages(): void
    {
        self::$sentMessages = [];
        self::$shouldFailNext = false;
        self::$failureReason = null;
    }

    public static function simulateFailureNext(bool $fail = true, ?string $reason = null): void
    {
        self::$shouldFailNext = $fail;
        self::$failureReason = $reason;
    }
}
