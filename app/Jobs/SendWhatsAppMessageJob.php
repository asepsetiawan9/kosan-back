<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WhatsAppProviderInterface;
use App\Repositories\Contracts\WaMessageRepositoryInterface;
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
        WaMessageRepositoryInterface $messageRepo
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

        // Random delay between 3-10 seconds to prevent anti-ban on unofficial gateways (skip in testing)
        if (!App::environment('testing')) {
            $minDelay = (int) config('services.whatsapp.send_delay_min', 3);
            $maxDelay = (int) config('services.whatsapp.send_delay_max', 10);
            if ($maxDelay >= $minDelay && $maxDelay > 0) {
                sleep(random_int($minDelay, $maxDelay));
            }
        }

        $attempts = $message->attempts + 1;
        $messageRepo->update($message, [
            'attempts' => $attempts,
        ]);

        $result = $provider->sendText($message->phone, (string) $message->body);

        if ($result->success) {
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
