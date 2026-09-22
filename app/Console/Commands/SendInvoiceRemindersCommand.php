<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendInvoiceRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send WhatsApp payment reminders to tenants on H-3 and H-0 of invoice due date';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::today()->toDateString();
        $hMinus3 = Carbon::today()->addDays(3)->toDateString();

        $this->info("Memeriksa tagihan jatuh tempo untuk H-0 ({$today}) dan H-3 ({$hMinus3})...");

        // Find unpaid or partially paid invoices with due_date matching today or in 3 days
        $invoices = Invoice::with('tenancy')
            ->whereIn('status', ['belum_bayar', 'sebagian_dibayar'])
            ->where(function ($query) use ($today, $hMinus3) {
                $query->whereDate('due_date', $today)
                    ->orWhereDate('due_date', $hMinus3);
            })
            ->get();


        $remindedCount = 0;

        foreach ($invoices as $invoice) {
            $tenancy = $invoice->tenancy;
            if (!$tenancy || !$tenancy->tenant_phone) {
                continue;
            }

            $isToday = $invoice->due_date->toDateString() === $today;
            $remaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;
            $formattedAmount = 'Rp ' . number_format($remaining, 0, ',', '.');

            if ($isToday) {
                $message = "PENTING: Halo {$tenancy->tenant_name}, hari ini ({$today}) adalah HARI JATUH TEMPO untuk tagihan sewa {$invoice->invoice_number} sebesar {$formattedAmount}. Mohon segera lakukan pembayaran melalui portal penghuni untuk menghindari denda/keterlambatan. Terima kasih.";
            } else {
                $message = "PENGINGAT: Halo {$tenancy->tenant_name}, tagihan sewa Anda {$invoice->invoice_number} sebesar {$formattedAmount} akan jatuh tempo dalam 3 hari pada tanggal {$invoice->due_date->toDateString()}. Silakan lakukan pembayaran melalui portal penghuni sebelum tanggal tersebut.";
            }

            SendWhatsAppNotificationJob::dispatch($tenancy->tenant_phone, $message, 'invoice_reminder');
            $remindedCount++;
        }

        $this->info("Pengingat tagihan berhasil dikirim ke {$remindedCount} penghuni.");
        Log::info("[CRON INVOICE REMINDERS] Sent reminders to {$remindedCount} tenants.");

        return Command::SUCCESS;
    }
}
