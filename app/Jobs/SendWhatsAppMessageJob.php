<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WhatsAppProviderInterface;
use App\Repositories\Contracts\WaMessageRepositoryInterface;
use App\Services\WhatsApp\WaAntiBanGuard;
use App\Services\WhatsApp\WaMessageHumanizer;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $messageId
    ) {
        $this->onQueue('wa-send');
    }

    public function handle(
        WhatsAppProviderInterface $provider,
        WaMessageRepositoryInterface $messageRepo,
        WaAntiBanGuard $antiBanGuard,
        WaMessageHumanizer $humanizer
    ): void {
        $message = $messageRepo->findById($this->messageId);

        if (!$message) {
            Log::warning("[SendWhatsAppMessageJob] WaMessage ID {$this->messageId} not found.");
            return;
        }

        if ($message->isSent()) {
            Log::info("[SendWhatsAppMessageJob] WaMessage ID {$this->messageId} already sent. Skipping.");
            return;
        }

        // Direct replies (chatbot interactions, proof confirmations, admin test sends) bypass business hours restriction
        $isDirectReply = str_starts_with((string) $message->template_key, 'bot_')
            || str_starts_with((string) $message->template_key, 'proof_')
            || (string) $message->related_type === 'chatbot'
            || (string) $message->related_type === 'test_send'
            || (string) $message->related_type === 'manual_admin';

        // Anti-ban check (hourly limit, daily limit, business hours, circuit breaker)
        $guardCheck = $antiBanGuard->canSend($isDirectReply);
        if (!$guardCheck['allowed']) {
            $retryAfter = $guardCheck['retry_after_seconds'] ?? 300;
            Log::warning("[SendWhatsAppMessageJob] Anti-ban rate limit active: {$guardCheck['reason']}. Releasing message {$message->id} back to queue for {$retryAfter} seconds.");

            $this->release($retryAfter);
            return;
        }

        // Randomized human-like delay to simulate natural pacing (skip in testing)
        if (!App::environment('testing')) {
            $delay = $antiBanGuard->getRandomDelay();
            if ($delay > 0) {
                sleep($delay);
            }
        }

        $attempts = $message->attempts + 1;
        $messageRepo->update($message, [
            'attempts' => $attempts,
        ]);

        // Humanize message to prevent identical fingerprint detection
        $body = $humanizer->humanize((string) $message->body);

        $result = $provider->sendText($message->phone, $body);

        if ($result->success) {
            $antiBanGuard->recordSent();

            $messageRepo->update($message, [
                'status' => 'sent',
                'sent_at' => now(),
                'provider_message_id' => $result->providerMessageId,
                'raw_payload' => $result->rawResponse,
                'error_message' => null,
            ]);

            Log::info("[SendWhatsAppMessageJob] Message {$message->id} successfully sent to {$message->phone}");
            return;
        }

        // Record failure in anti-ban circuit breaker
        $antiBanGuard->recordFailure();

        // Failed attempt
        $messageRepo->update($message, [
            'error_message' => $result->errorMessage,
            'raw_payload' => $result->rawResponse,
        ]);

        if ($attempts >= $this->tries || $this->attempts() >= $this->tries) {
            $messageRepo->update($message, [
                'status' => 'failed',
            ]);

            Log::error("[SendWhatsAppMessageJob] Message {$message->id} permanently failed after {$this->tries} attempts. Error: {$result->errorMessage}");
            return;
        }

        // Re-throw exception to let queue worker retry with backoff
        throw new Exception("WhatsApp delivery failed (attempt {$attempts}): {$result->errorMessage}");
    }
}
