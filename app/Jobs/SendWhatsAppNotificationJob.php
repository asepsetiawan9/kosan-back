<?php

declare(strict_types=1);

namespace App\Jobs;

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
    public function handle(): void
    {
        // No-op: Integrasi direct WA API telah digantikan dengan penagihan semi-manual via link WA Web.
        Log::info('[WHATSAPP NOTIFICATION NO-OP]', [
            'to' => $this->phone,
            'context' => $this->context,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
