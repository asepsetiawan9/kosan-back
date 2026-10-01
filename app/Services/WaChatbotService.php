<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessIncomingMediaJob;
use App\Models\Complaint;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Repositories\Contracts\WaConversationRepositoryInterface;
use App\Services\WhatsApp\DTO\IncomingMessage;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use App\Services\WhatsApp\WaAntiBanGuard;

class WaChatbotService
{
    public function __construct(
        protected WaConversationRepositoryInterface $conversationRepo,
        protected WaMessageService $messageService,
        protected WaTemplateRenderer $templateRenderer,
        protected WaAntiBanGuard $antiBanGuard
    ) {}

    /**
     * Main entry point to process incoming message from WhatsApp Webhook.
     */
    public function handleIncomingMessage(IncomingMessage $msg, WaMessage $waMessage): void
    {
        $normalizedPhone = PhoneNumber::normalize($msg->from);

        // 1. Identify tenant
        $tenant = $this->findTenant($normalizedPhone);

        if (!$tenant) {
            $this->handleUnknownSender($msg, $waMessage);
            return;
        }

        // Link tenant to wa_messages record
        $waMessage->update(['tenant_id' => $tenant->id]);

        // 2. Get or create conversation state
        $conversation = $this->conversationRepo->getOrCreate($normalizedPhone, $tenant->id);

        if ($conversation->isExpired()) {
            $conversation->resetToIdle();
        }

        // 3. Process based on message type
        if ($msg->type === 'image' || !empty($msg->media['url'])) {
            $this->handleIncomingImage($msg, $waMessage, $tenant, $conversation);
        } else {
            $this->handleIncomingText($msg, $waMessage, $tenant, $conversation);
        }
    }

    /**
     * Find registered tenant by normalized phone number.
     */
    public function findTenant(string $normalizedPhone): ?User
    {
        return User::where('role', 'penyewa')
            ->where(function ($q) use ($normalizedPhone) {
                $q->where('wa_number', $normalizedPhone)
                    ->orWhere('phone', $normalizedPhone);
            })
            ->first();
    }

    /**
     * Handle message from unregistered phone number.
     */
    protected function handleUnknownSender(IncomingMessage $msg, WaMessage $waMessage): void
    {
        Log::info("[WaChatbot] Unknown sender: {$msg->from}");

        // Anti-ban: Throttle replies to unregistered numbers to prevent spam loops
        if (!$this->antiBanGuard->canReplyToUnknown($msg->from)) {
            Log::warning("[WaChatbot] Unknown sender {$msg->from} throttled by anti-ban. Suppressing auto-reply.");
            $waMessage->update([
                'status' => 'ignored',
                'error_message' => 'Dibatasi sistem anti-ban: pengirim tak dikenal melebihi batas balasan harian.',
            ]);
            return;
        }

        $this->antiBanGuard->recordUnknownReply($msg->from);

        $waMessage->update([
            'status' => 'ignored',
            'error_message' => 'Nomor pengirim belum terdaftar sebagai penghuni.',
        ]);

        $reply = $this->templateRenderer->render('bot_unknown_number', [
            'nama_kos' => config('app.name', 'Kosan Eksklusif'),
        ]);

        $this->messageService->send($msg->from, $reply, [
            'template_key' => 'bot_unknown_number',
            'force' => true,
        ]);
    }

    /**
     * Handle incoming image/proof from registered tenant.
     */
    protected function handleIncomingImage(
        IncomingMessage $msg,
        WaMessage $waMessage,
        User $tenant,
        WaConversation $conversation
    ): void {
        $invoices = Invoice::with(['tenancy.room'])
            ->whereHas('tenancy', function ($q) use ($tenant) {
                $q->where('user_id', $tenant->id);
            })
            ->whereIn('status', ['belum_bayar', 'sebagian_dibayar', 'terlambat', 'menunggu_verifikasi'])
            ->orderBy('due_date', 'asc')
            ->get();

        if ($invoices->isEmpty()) {
            $waMessage->update(['status' => 'processed']);
            $this->messageService->send(
                $msg->from,
                "Halo {$tenant->name}, saat ini Anda tidak memiliki tagihan aktif yang belum dibayar. Terima kasih! 🙏",
                ['force' => true]
            );
            return;
        }

        // Single unpaid invoice: directly dispatch media processing job
        if ($invoices->count() === 1) {
            $invoice = $invoices->first();
            $conversation->resetToIdle();

            ProcessIncomingMediaJob::dispatch(
                $waMessage->id,
                $invoice->id,
                $tenant->id,
                $msg->media ?? []
            );
            return;
        }

        // Multiple unpaid invoices: ask tenant which invoice this proof is for
        $conversation->setAwaitingInvoiceChoice(
            $invoices->pluck('id')->toArray(),
            $msg->media ?? [],
            30
        );

        $lines = [];
        $idx = 1;
        foreach ($invoices as $inv) {
            $kamar = $inv->tenancy?->room?->room_number ?? '-';
            $periode = $inv->period ?? $inv->invoice_number;
            $remaining = max(0, (float) $inv->total_amount - (float) $inv->paid_amount);
            $formatted = 'Rp ' . number_format($remaining, 0, ',', '.');
            $due = $inv->due_date ? $inv->due_date->format('d/m/Y') : '-';
            $lines[] = "{$idx}. [Kamar {$kamar}] {$periode} - {$formatted} (Jatuh Tempo: {$due})";
            $idx++;
        }

        $daftarTagihan = implode("\n", $lines);

        $reply = $this->templateRenderer->render('bot_choose_invoice', [
            'nama' => $tenant->name,
            'daftar_tagihan' => $daftarTagihan,
        ]);

        $this->messageService->send($msg->from, $reply, [
            'template_key' => 'bot_choose_invoice',
            'force' => true,
        ]);

        $waMessage->update(['status' => 'processed']);
    }

    /**
     * Handle incoming text message and state transitions.
     */
    protected function handleIncomingText(
        IncomingMessage $msg,
        WaMessage $waMessage,
        User $tenant,
        WaConversation $conversation
    ): void {
        $text = trim((string) $msg->text);
        $lower = strtolower($text);

        // State: Awaiting Invoice Choice
        if ($conversation->state === 'awaiting_invoice_choice') {
            $this->handleAwaitingInvoiceChoice($text, $waMessage, $tenant, $conversation);
            return;
        }

        // State: Idle commands
        match ($lower) {
            'menu', 'bantuan', 'help' => $this->commandMenu($msg->from, $tenant),
            'tagihan', 'cek', 'status' => $this->commandTagihan($msg->from, $tenant),
            'aduan', 'keluhan', 'lapor', 'komplain' => $this->commandAduan($msg->from, $tenant),
            'stop' => $this->commandStop($msg->from, $tenant),
            'mulai' => $this->commandMulai($msg->from, $tenant),
            default => $this->commandUnknown($msg->from),
        };

        $waMessage->update(['status' => 'processed']);
    }

    protected function handleAwaitingInvoiceChoice(
        string $text,
        WaMessage $waMessage,
        User $tenant,
        WaConversation $conversation
    ): void {
        $invoiceIds = $conversation->context['invoice_ids'] ?? [];

        if (is_numeric($text)) {
            $choice = (int) $text;

            if ($choice >= 1 && $choice <= count($invoiceIds)) {
                $selectedInvoiceId = $invoiceIds[$choice - 1];
                $pendingMedia = $conversation->context['pending_media'] ?? null;

                $conversation->resetToIdle();

                if ($pendingMedia) {
                    ProcessIncomingMediaJob::dispatch(
                        $waMessage->id,
                        $selectedInvoiceId,
                        $tenant->id,
                        $pendingMedia
                    );
                } else {
                    $this->messageService->send(
                        $conversation->phone,
                        "Pilihan tagihan berhasil dipilih. Silakan kirimkan foto bukti transfer sekarang.",
                        ['force' => true]
                    );
                }

                $waMessage->update(['status' => 'processed']);
                return;
            }
        }

        // Invalid response handling (max 2 attempts before cancelling)
        $attempts = (int) ($conversation->context['invalid_attempts'] ?? 0) + 1;

        if ($attempts >= 2) {
            $conversation->resetToIdle();
            $this->messageService->send(
                $conversation->phone,
                "Pilihan dibatalkan karena nomor yang dimasukkan tidak valid. Silakan kirim ulang foto bukti transfer jika ingin melaporkan pembayaran sewa.",
                ['force' => true]
            );
        } else {
            $newContext = $conversation->context ?? [];
            $newContext['invalid_attempts'] = $attempts;
            $conversation->update(['context' => $newContext]);

            $maxChoice = count($invoiceIds);
            $this->messageService->send(
                $conversation->phone,
                "Pilihan tidak valid. Silakan balas hanya dengan angka 1 sampai {$maxChoice} sesuai urutan daftar tagihan di atas.",
                ['force' => true]
            );
        }

        $waMessage->update(['status' => 'processed']);
    }

    protected function commandMenu(string $phone, User $tenant): void
    {
        $reply = $this->templateRenderer->render('bot_help', [
            'nama' => $tenant->name,
            'nama_kos' => config('app.name', 'Kosan Eksklusif'),
        ]);

        $this->messageService->send($phone, $reply, [
            'template_key' => 'bot_help',
            'force' => true,
        ]);
    }

    protected function commandTagihan(string $phone, User $tenant): void
    {
        $invoices = Invoice::with(['tenancy.room'])
            ->whereHas('tenancy', function ($q) use ($tenant) {
                $q->where('user_id', $tenant->id);
            })
            ->whereIn('status', ['belum_bayar', 'sebagian_dibayar', 'terlambat', 'menunggu_verifikasi'])
            ->orderBy('due_date', 'asc')
            ->get();

        if ($invoices->isEmpty()) {
            $this->messageService->send(
                $phone,
                "Halo {$tenant->name}, seluruh tagihan sewa kos Anda saat ini sudah LUNAS. Terima kasih! 🙏",
                ['force' => true]
            );
            return;
        }

        $lines = ["Halo {$tenant->name}, berikut rincian tagihan sewa Anda yang aktif:"];
        foreach ($invoices as $inv) {
            $kamar = $inv->tenancy?->room?->room_number ?? '-';
            $periode = $inv->period ?? $inv->invoice_number;
            $remaining = max(0, (float) $inv->total_amount - (float) $inv->paid_amount);
            $formatted = 'Rp ' . number_format($remaining, 0, ',', '.');
            $due = $inv->due_date ? $inv->due_date->format('d/m/Y') : '-';
            $statusLabel = $inv->status === 'menunggu_verifikasi' ? ' (Menunggu Verifikasi)' : '';
            $lines[] = "• [Kamar {$kamar}] {$periode}: {$formatted} (Jatuh Tempo: {$due}){$statusLabel}";
        }

        $lines[] = "\nRekening Pembayaran: BCA 1234567890 a/n Pengelola Kos";
        $lines[] = "Setelah melakukan transfer, silakan kirimkan *foto bukti transfer* langsung ke nomor WhatsApp ini.";

        $this->messageService->send($phone, implode("\n", $lines), [
            'force' => true,
        ]);
    }

    protected function commandStop(string $phone, User $tenant): void
    {
        $tenant->update(['wa_opt_in' => false]);

        $this->messageService->send(
            $phone,
            "Halo {$tenant->name}, Anda telah berhenti menerima notifikasi pengingat WhatsApp. Ketik *mulai* jika sewaktu-waktu ingin mengaktifkannya kembali.",
            ['force' => true]
        );
    }

    protected function commandMulai(string $phone, User $tenant): void
    {
        $tenant->update([
            'wa_opt_in' => true,
            'wa_opt_in_at' => now(),
        ]);

        $this->messageService->send(
            $phone,
            "Halo {$tenant->name}, notifikasi pengingat WhatsApp Anda telah AKTIF kembali. Terima kasih! 🙏",
            ['force' => true]
        );
    }

    protected function commandAduan(string $phone, User $tenant): void
    {
        $activeTenancy = $tenant->tenancies()->where('status', 'aktif')->latest()->first();

        if (!$activeTenancy) {
            $this->messageService->send(
                $phone,
                "Halo {$tenant->name}, Anda belum memiliki data sewa kos aktif untuk menyampaikan tiket aduan. Silakan hubungi pengelola kos.",
                ['force' => true]
            );
            return;
        }

        $complaints = Complaint::where('tenancy_id', $activeTenancy->id)
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        $lines = ["Halo {$tenant->name}, berikut layanan informasi aduan & perbaikan fasilitas kos:"];

        if ($complaints->isNotEmpty()) {
            $lines[] = "\nRiwayat Aduan Terkini:";
            foreach ($complaints as $c) {
                $categoryLabel = match ($c->category) {
                    'fasilitas_rusak' => 'Fasilitas Rusak',
                    'kebersihan' => 'Kebersihan',
                    'keamanan' => 'Keamanan',
                    default => 'Lainnya',
                };
                $statusLabel = match ($c->status) {
                    'baru' => 'Menunggu Diproses',
                    'diproses' => 'Sedang Dikerjakan',
                    'selesai' => 'Selesai',
                    default => ucfirst($c->status),
                };
                $lines[] = "• [{$categoryLabel}] {$c->description} (Status: {$statusLabel})";
            }
        }

        $lines[] = "\nUntuk melaporkan aduan baru disertai foto kendala, silakan akses Portal Penghuni:";
        $lines[] = url('/portal/complaints/new');
        $lines[] = "Tim pengelola kos akan segera menindaklanjuti keluhan Anda. Terima kasih! 🙏";

        $this->messageService->send(
            $phone,
            implode("\n", $lines),
            ['force' => true]
        );
    }

    protected function commandUnknown(string $phone): void
    {
        $cacheKey = 'wa_bot_unknown_' . $phone;
        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->addMinutes(10));

        $this->messageService->send(
            $phone,
            "Maaf, kami belum memahami pesan tersebut. Ketik *menu* untuk melihat panduan layanan atau kirim *foto bukti transfer* untuk konfirmasi pembayaran sewa.",
            ['force' => true]
        );
    }
}
