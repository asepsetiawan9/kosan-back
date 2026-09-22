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
        // Standar log terstruktur siap integrasi Fonnte/Wablas di Fase 4
        Log::info('[WHATSAPP NOTIFICATION DISPATCHED]', [
            'to' => $this->phone,
            'context' => $this->context,
            'message' => $this->message,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Catatan: Adaptor API eksternal dapat dihubungkan di sini
    }
}
