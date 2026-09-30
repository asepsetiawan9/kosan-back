<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WaCleanupPayloadsCommand extends Command
{
    protected $signature = 'wa:cleanup-payloads {--days=90 : Batas usia raw_payload dalam hari}';
    protected $description = 'Membersihkan raw_payload pesan WhatsApp yang telah berusia lebih dari N hari demi privasi dan efisiensi database';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $this->info("Menjalankan pembersihan payload WhatsApp lebih lama dari {$days} hari (sebelum {$cutoff->format('Y-m-d H:i:s')})...");

        // 1. Clean raw_payload in wa_messages
        $query = WaMessage::where('created_at', '<', $cutoff)
            ->whereNotNull('raw_payload');

        $countMessages = $query->count();
        if ($countMessages > 0) {
            $query->update(['raw_payload' => null]);
            $this->info("✓ Berhasil membersihkan {$countMessages} raw_payload dari tabel wa_messages.");
        } else {
            $this->line("- Tidak ditemukan raw_payload wa_messages yang memenuhi kriteria umur > {$days} hari.");
        }

        // 2. Prune old stale conversations expired > 30 days
        $convCutoff = now()->subDays(30);
        $deletedConversations = WaConversation::where('expires_at', '<', $convCutoff)->delete();
        if ($deletedConversations > 0) {
            $this->info("✓ Berhasil memusnahkan {$deletedConversations} sesi percakapan usang (> 30 hari).");
        }

        Log::info("[WaCleanupPayloads] Cleaned {$countMessages} raw payloads (> {$days} days) and pruned {$deletedConversations} old conversations.");

        $this->info("Pembersihan payload WhatsApp selesai dengan sukses.");

        return self::SUCCESS;
    }
}
