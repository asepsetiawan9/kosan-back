<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WaReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class WaRunRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wa:run-reminders
                            {--dry-run : Simulate execution without sending messages or saving logs}
                            {--date= : Target execution date in YYYY-MM-DD format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Jalankan pengingat tagihan WhatsApp otomatis bertingkat sesuai aturan wa_reminder_rules';

    /**
     * Execute the console command.
     */
    public function handle(WaReminderService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $dateOption = $this->option('date');

        $targetDate = null;
        if ($dateOption) {
            try {
                $targetDate = Carbon::parse($dateOption, 'Asia/Jakarta');
            } catch (\Throwable) {
                $this->error("Format tanggal tidak valid: {$dateOption}. Gunakan format YYYY-MM-DD.");
                return Command::FAILURE;
            }
        }

        $dateDisplay = ($targetDate ?? Carbon::today('Asia/Jakarta'))->toDateString();
        $this->info("Menjalankan scheduler pengingat WhatsApp untuk tanggal {$dateDisplay}" . ($dryRun ? ' [DRY-RUN SIMULASI]' : '') . '...');

        $result = $service->runReminders($dryRun, $targetDate);

        $this->newLine();
        $this->table(
            ['Metrik', 'Nilai'],
            [
                ['Tanggal Eksekusi', $result['target_date']],
                ['Mode Dry Run', $result['dry_run'] ? 'Ya (Simulasi)' : 'Tidak (Kirim Nyata)'],
                ['Aturan Dievaluasi', $result['rules_evaluated']],
                ['Tagihan Dicek', $result['invoices_checked']],
                ['Pengingat Terkirim', $result['reminders_sent']],
                ['Dilewati: Sudah Pernah Dikirim', $result['skipped']['already_sent']],
                ['Dilewati: Bukti Bayar Pending', $result['skipped']['pending_payment']],
                ['Dilewati: Opt-out Penghuni', $result['skipped']['opted_out']],
            ]
        );

        if (!empty($result['items'])) {
            $this->newLine();
            $this->info('Rincian Item:');

            $rows = array_map(function ($item) {
                return [
                    $item['invoice_number'],
                    $item['tenant_name'],
                    $item['phone'],
                    $item['rule_name'],
                    $item['due_date'],
                    $item['status'] === 'sent' ? 'TERKIRIM' : ($item['status'] === 'ready' ? 'SIAP' : 'DILEWATI (' . ($item['skip_reason'] ?? '-') . ')'),
                ];
            }, $result['items']);

            $this->table(
                ['No Invoice', 'Penghuni', 'Nomor WA', 'Aturan', 'Jatuh Tempo', 'Status'],
                $rows
            );
        }

        return Command::SUCCESS;
    }
}
