<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WhatsAppProviderInterface;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WaMessage;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\WaMessageService;
use App\Services\WaTemplateRenderer;
use App\Services\WhatsApp\DTO\IncomingMessage;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessIncomingMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public string $waMessageId,
        public string $invoiceId,
        public string $tenantId,
        public array $mediaPayload
    ) {}

    public function handle(
        WhatsAppProviderInterface $provider,
        PaymentRepositoryInterface $paymentRepo,
        WaMessageService $messageService,
        WaTemplateRenderer $templateRenderer
    ): void {
        $waMessage = WaMessage::find($this->waMessageId);
        $invoice = Invoice::with(['tenancy.room', 'tenancy.user'])->find($this->invoiceId);
        $tenant = User::find($this->tenantId);

        if (!$waMessage || !$invoice || !$tenant) {
            Log::warning('[ProcessIncomingMediaJob] Aborted: Missing record', [
                'wa_message_id' => $this->waMessageId,
                'invoice_id' => $this->invoiceId,
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        $incomingMsg = new IncomingMessage(
            providerMessageId: $waMessage->provider_message_id ?? Str::uuid()->toString(),
            from: $waMessage->phone,
            type: 'image',
            text: $waMessage->body,
            media: $this->mediaPayload,
            timestamp: now(),
            raw: $waMessage->raw_payload ?? []
        );

        try {
            $downloadResult = $provider->downloadMedia($incomingMsg);
        } catch (Exception $e) {
            Log::error('[ProcessIncomingMediaJob] Failed downloading media: ' . $e->getMessage(), [
                'wa_message_id' => $this->waMessageId,
            ]);

            $waMessage->update([
                'status' => 'failed',
                'error_message' => 'Gagal mengunduh berkas bukti transfer: ' . $e->getMessage(),
            ]);

            $messageService->send(
                $waMessage->phone,
                "Halo {$tenant->name}, kami mengalami kendala saat mengunduh berkas foto bukti transfer Anda. Silakan kirimkan ulang foto bukti transfer tersebut. Terima kasih."
            );

            return;
        }

        $content = $downloadResult->content;
        $maxBytes = (int) config('services.whatsapp.media_max_mb', 5) * 1024 * 1024;

        if (strlen($content) > $maxBytes || strlen($content) === 0) {
            $waMessage->update([
                'status' => 'failed',
                'error_message' => 'Ukuran berkas melebihi batas maksimal 5MB atau berkas kosong.',
            ]);

            $messageService->send(
                $waMessage->phone,
                "Halo {$tenant->name}, ukuran berkas foto melebihi batas maksimal 5MB. Silakan kirimkan foto bukti transfer dengan resolusi standar."
            );
            return;
        }

        // Validate magic bytes
        if (!$this->isValidMagicBytes($content, $downloadResult->mimeType)) {
            $waMessage->update([
                'status' => 'failed',
                'error_message' => 'Format berkas tidak valid atau bukan gambar asli.',
            ]);

            $messageService->send(
                $waMessage->phone,
                "Halo {$tenant->name}, berkas yang dikirim tidak terbaca sebagai format gambar yang didukung (JPG, PNG, WEBP, PDF). Mohon kirimkan bukti transfer berupa foto langsung."
            );
            return;
        }

        // Calculate hash SHA256 & detect duplicates
        $sha256 = hash('sha256', $content);
        $isDuplicate = Payment::where('proof_sha256', $sha256)->exists();

        // Determine extension & save to private storage
        $ext = match ($downloadResult->mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'jpg',
        };

        $filename = 'proof_' . Str::uuid()->toString() . '.' . $ext;
        $storagePath = 'private/payment_proofs/' . $filename;
        Storage::put($storagePath, $content);

        $remaining = max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount);

        // Create Payment record
        $payment = $paymentRepo->create([
            'invoice_id' => $invoice->id,
            'amount' => $remaining,
            'method' => 'manual_transfer',
            'source' => 'whatsapp',
            'proof_file' => $storagePath,
            'proof_mime' => $downloadResult->mimeType,
            'proof_size' => $downloadResult->fileSize,
            'proof_sha256' => $sha256,
            'wa_message_id' => $waMessage->id,
            'claimed_amount' => $remaining,
            'is_duplicate_suspect' => $isDuplicate,
            'status' => 'pending',
            'notes' => 'Bukti transfer masuk via WhatsApp Bot' . ($isDuplicate ? ' [DUPLIKAT TERDETEKSI]' : ''),
        ]);

        // Update invoice status to 'menunggu_verifikasi' if unpaid/overdue
        if (in_array($invoice->status, ['belum_bayar', 'terlambat', 'sebagian_dibayar'], true)) {
            $invoice->update(['status' => 'menunggu_verifikasi']);
        }

        // Update incoming message status
        $waMessage->update([
            'status' => 'processed',
            'related_type' => Payment::class,
            'related_id' => $payment->id,
            'media_path' => $storagePath,
        ]);

        // Reply confirmation to tenant via template proof_received
        $replyText = $templateRenderer->render('proof_received', [
            'nama' => $tenant->name,
            'kamar' => $invoice->tenancy?->room?->room_number ?? '-',
            'periode' => $invoice->period ?? $invoice->invoice_number,
            'nominal' => 'Rp ' . number_format($remaining, 0, ',', '.'),
        ]);

        $messageService->send($waMessage->phone, $replyText, [
            'template_key' => 'proof_received',
            'related_type' => Payment::class,
            'related_id' => $payment->id,
            'force' => true,
        ]);

        // Admin Notification if configured
        $adminNotifyNumber = config('services.whatsapp.admin_notify_number');
        if (!empty($adminNotifyNumber)) {
            $duplicateNote = $isDuplicate ? "\n⚠️ PERINGATAN: Berkas bukti terindikasi duplikat (hash identik)!" : '';
            $adminText = $templateRenderer->render('admin_new_proof', [
                'nama' => $tenant->name,
                'kamar' => $invoice->tenancy?->room?->room_number ?? '-',
                'periode' => $invoice->period ?? $invoice->invoice_number,
                'nominal' => 'Rp ' . number_format($remaining, 0, ',', '.'),
                'nama_kos' => config('app.name', 'Kosan Eksklusif'),
                'url_admin' => url('/dashboard/wa-payments'),
            ]) . $duplicateNote;

            $messageService->send($adminNotifyNumber, $adminText, [
                'template_key' => 'admin_new_proof',
                'related_type' => Payment::class,
                'related_id' => $payment->id,
                'force' => true,
            ]);
        }
    }

    protected function isValidMagicBytes(string $content, string $mime): bool
    {
        if (strlen($content) < 4) {
            return false;
        }

        // JPEG: FF D8 FF
        if (str_starts_with($content, "\xFF\xD8\xFF")) {
            return true;
        }

        // PNG: \x89PNG\r\n\x1a\n
        if (str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
            return true;
        }

        // WEBP: RIFF....WEBP
        if (str_starts_with($content, 'RIFF') && str_contains(substr($content, 8, 8), 'WEBP')) {
            return true;
        }

        // PDF: %PDF
        if (str_starts_with($content, '%PDF')) {
            return true;
        }

        // Fallback for image mimes if recognized by finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_buffer($finfo, $content);
            finfo_close($finfo);
            return in_array($detected, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
        }

        return false;
    }
}
