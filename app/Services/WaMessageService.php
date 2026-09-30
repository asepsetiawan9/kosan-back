<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WhatsAppProviderInterface;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\User;
use App\Models\WaMessage;
use App\Repositories\Contracts\WaMessageRepositoryInterface;
use App\Support\PhoneNumber;
use InvalidArgumentException;

class WaMessageService
{
    public function __construct(
        protected WhatsAppProviderInterface $provider,
        protected WaMessageRepositoryInterface $messageRepo
    ) {}

    /**
     * Send an outbound WhatsApp message.
     *
     * @param string $phone Destination phone number (any format, will be normalized)
     * @param string $body Text message content
     * @param array<string, mixed> $meta Optional metadata (template_key, related_type, related_id, force)
     */
    public function send(string $phone, string $body, array $meta = []): WaMessage
    {
        $normalizedPhone = PhoneNumber::normalize($phone);

        if (empty($normalizedPhone)) {
            throw new InvalidArgumentException('Nomor tujuan WhatsApp tidak boleh kosong.');
        }

        // Match tenant by normalized wa_number or normalized phone
        $tenant = User::where('wa_number', $normalizedPhone)
            ->orWhere('phone', $normalizedPhone)
            ->first();

        // Check opt-in (skip if tenant opted out unless explicitly forced)
        $isOptedOut = $tenant && $tenant->wa_opt_in === false;
        $isForced = (bool) ($meta['force'] ?? false);

        $status = ($isOptedOut && !$isForced) ? 'ignored' : 'queued';
        $errorMessage = ($isOptedOut && !$isForced) ? 'Penghuni telah menonaktifkan notifikasi WhatsApp (opt-out).' : null;

        $providerName = (string) config('services.whatsapp.provider', 'fake');

        $message = $this->messageRepo->create([
            'direction' => 'out',
            'tenant_id' => $tenant?->id,
            'phone' => $normalizedPhone,
            'provider' => $providerName,
            'type' => $meta['type'] ?? 'text',
            'body' => $body,
            'template_key' => $meta['template_key'] ?? null,
            'status' => $status,
            'error_message' => $errorMessage,
            'related_type' => $meta['related_type'] ?? null,
            'related_id' => $meta['related_id'] ?? null,
            'attempts' => 0,
        ]);

        if ($status === 'queued') {
            SendWhatsAppMessageJob::dispatch($message->id);
        }

        return $message;
    }

    /**
     * Resend a failed message.
     */
    public function resend(WaMessage $message): WaMessage
    {
        $updated = $this->messageRepo->update($message, [
            'status' => 'queued',
            'error_message' => null,
            'attempts' => 0,
        ]);

        SendWhatsAppMessageJob::dispatch($updated->id);

        return $updated;
    }

    /**
     * Get aggregate health and connection status for admin dashboard.
     *
     * @return array<string, mixed>
     */
    public function getConnectionStatus(): array
    {
        $providerStatus = $this->provider->checkConnection();
        $failed24h = $this->messageRepo->getRecentFailedCount(24);
        $sent24h = $this->messageRepo->getRecentSentCount(24);

        $hasHighFailureRate = $failed24h >= 5;

        return [
            ...$providerStatus,
            'sent_last_24h' => $sent24h,
            'failed_last_24h' => $failed24h,
            'has_high_failure_rate' => $hasHighFailureRate,
            'webhook_url' => url('/api/webhooks/whatsapp'),
            'webhook_secret_set' => !empty(config('services.whatsapp.webhook_secret')),
            'send_delay' => [
                'min' => config('services.whatsapp.send_delay_min', 3),
                'max' => config('services.whatsapp.send_delay_max', 10),
            ],
        ];
    }
}
