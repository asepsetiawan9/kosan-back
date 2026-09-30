<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\Contracts\WaConversationRepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WaExpireConversationsCommand extends Command
{
    protected $signature = 'wa:expire-conversations';
    protected $description = 'Reset state chatbot WhatsApp yang telah melewati TTL ke status idle';

    public function handle(WaConversationRepositoryInterface $conversationRepo): int
    {
        $count = $conversationRepo->expireStale();

        if ($count > 0) {
            $this->info("Berhasil me-reset {$count} percakapan WhatsApp yang kedaluwarsa.");
            Log::info("[WaExpireConversationsCommand] Expired {$count} stale conversations.");
        } else {
            $this->info("Tidak ada percakapan WhatsApp yang kedaluwarsa.");
        }

        return self::SUCCESS;
    }
}
