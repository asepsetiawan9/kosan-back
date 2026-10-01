<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\WaMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $phone;
    public string $message;
    public string $context;

    /**
     * Create a new job instance.
     */
    public function __construct(string $phone, string $message, string $context = 'notification')
    {
        $this->phone = $phone;
        $this->message = $message;
        $this->context = $context;
    }

    /**
     * Execute the job.
     */
    public function handle(WaMessageService $messageService): void
    {
        Log::info('[WHATSAPP NOTIFICATION DISPATCHED]', [
            'to' => $this->phone,
            'context' => $this->context,
            'message' => $this->message,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Route through unified WaMessageService with anti-ban guard and queue protection
        try {
            $messageService->send($this->phone, $this->message, [
                'template_key' => $this->context,
                'force' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SendWhatsAppNotificationJob] Skipped queuing WaMessage: ' . $e->getMessage());
        }
    }
}
