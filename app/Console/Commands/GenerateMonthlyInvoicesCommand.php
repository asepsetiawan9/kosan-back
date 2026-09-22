<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenancy;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateMonthlyInvoicesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:generate-monthly {--force : Generate invoice regardless of billing_due_day matching} {--period= : Specific period YYYY-MM}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate monthly rental invoices for active tenancies based on billing_due_day';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::today();
        $currentDay = (int) $today->day;
        $period = $this->option('period') ?: $today->format('Y-m');
        $force = (bool) $this->option('force');

        $this->info("Menjalankan pembuatan invoice otomatis untuk periode {$period} (Hari: {$currentDay})...");

        $activeTenancies = Tenancy::with('room')
            ->where('status', 'aktif')
            ->get();

        $generatedCount = 0;
        $skippedCount = 0;

        foreach ($activeTenancies as $tenancy) {
            // Check if today matches billing_due_day unless --force is specified
            if (!$force && (int) $tenancy->billing_due_day !== $currentDay) {
                continue;
            }

            // Guard against duplicate invoices for the same tenancy and period
            $existingInvoice = Invoice::where('tenancy_id', $tenancy->id)
                ->where('period', $period)
                ->first();

            if ($existingInvoice) {
                $skippedCount++;
                continue;
            }

            $room = $tenancy->room;
            $basePrice = $room ? (float) $room->base_price : 0.0;
            $dueDate = Carbon::createFromFormat('Y-m', $period)
                ->setDay(min((int) $tenancy->billing_due_day, 28))
                ->addDays(7)
                ->toDateString();

            DB::transaction(function () use ($tenancy, $room, $basePrice, $period, $dueDate, &$generatedCount) {
                $invoiceNumber = 'INV/' . str_replace('-', '', $period) . '/' .
                    ($room ? $room->room_number : 'UNIT') . '/' .
                    strtoupper(Str::random(4));

                $invoice = Invoice::create([
                    'tenancy_id' => $tenancy->id,
                    'invoice_number' => $invoiceNumber,
                    'period' => $period,
                    'total_amount' => $basePrice,
                    'paid_amount' => 0,
                    'status' => 'belum_bayar',
                    'due_date' => $dueDate,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => "Biaya Sewa Kamar " . ($room ? $room->room_number : '') . " Periode {$period}",
                    'amount' => $basePrice,
                    'item_type' => 'sewa',
                ]);

                $generatedCount++;

                // Notify tenant
                if ($tenancy->tenant_phone) {
                    $msg = "Halo {$tenancy->tenant_name}, tagihan sewa kamar Anda untuk periode {$period} telah diterbitkan dengan nomor {$invoiceNumber} sebesar Rp " .
                        number_format($basePrice, 0, ',', '.') .
                        ". Batas jatuh tempo: {$dueDate}. Silakan lakukan pembayaran melalui portal penghuni.";
                    SendWhatsAppNotificationJob::dispatch($tenancy->tenant_phone, $msg, 'monthly_invoice_generated');
                }
            });
        }

        $this->info("Selesai. Berhasil generate: {$generatedCount} invoice. Dilewati (sudah ada): {$skippedCount}.");
        Log::info("[CRON INVOICE GENERATE] Period: {$period}, Generated: {$generatedCount}, Skipped: {$skippedCount}");

        return Command::SUCCESS;
    }
}
