<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MarkOverdueInvoicesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:mark-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark unpaid invoices past due date as overdue (terlambat)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::today()->toDateString();

        $this->info("Memeriksa tagihan tertunggak sebelum tanggal {$today}...");

        // Only mark status 'belum_bayar' that has passed due_date
        // Do NOT touch 'menunggu_verifikasi', 'lunas', or 'dibatalkan'
        $updated = Invoice::where('status', 'belum_bayar')
            ->whereDate('due_date', '<', $today)
            ->update([
                'status' => 'terlambat',
            ]);

        $this->info("Sebanyak {$updated} tagihan berhasil diperbarui ke status 'terlambat'.");
        Log::info("[CRON INVOICE OVERDUE] Marked {$updated} invoices as overdue.");

        return Command::SUCCESS;
    }
}
