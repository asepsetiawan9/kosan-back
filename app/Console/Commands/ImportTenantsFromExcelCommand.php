<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TenantExcelImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportTenantsFromExcelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:tenants-from-excel 
                            {filepath? : Path ke berkas Excel (.xlsx / .xls)} 
                            {--dry-run : Uji coba pemetaan data tanpa menulis ke database}
                            {--force : Jalankan impor ke database tanpa konfirmasi}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Impor data penghuni, kamar, dan tagihan dari berkas Excel ke database SIKOS';

    /**
     * Execute the console command.
     */
    public function handle(TenantExcelImportService $importService): int
    {
        $filePath = $this->argument('filepath');

        // Auto-detect default files if none provided
        if (empty($filePath)) {
            $candidates = [
                base_path('../data penguni ksoan.xlsx'),
                base_path('../data_penghuni_kos_ai_ready.xlsx'),
                base_path('data penguni ksoan.xlsx'),
                base_path('data_penghuni_kos_ai_ready.xlsx'),
            ];

            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    $filePath = $candidate;
                    break;
                }
            }
        }

        if (empty($filePath) || !file_exists($filePath)) {
            $this->error("Berkas Excel tidak ditemukan. Silakan tentukan lokasi berkas, contoh:");
            $this->line("  php artisan import:tenants-from-excel \"../data penguni ksoan.xlsx\" --dry-run");
            return Command::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');

        $this->info("===============================================================================");
        $this->info(" SIKOS — IMPOR DATA PENGHUNI & KAMAR DARI EXCEL");
        $this->info("===============================================================================");
        $this->line("📂 Membaca berkas: <comment>{$filePath}</comment>");
        $this->line("⚙️  Mode: " . ($isDryRun ? '<fg=yellow;options=bold>DRY-RUN (Simulasi Tanpa Simpan)</>' : '<fg=green;options=bold>EKSEKUSI DATABASE</>'));
        $this->newLine();

        try {
            $parsedData = $importService->parseFile($filePath);
        } catch (\Throwable $e) {
            $this->error("Gagal membaca berkas Excel: " . $e->getMessage());
            return Command::FAILURE;
        }

        $rows = $parsedData['rows'];
        $summary = $parsedData['summary'];

        if (empty($rows)) {
            $this->warn("Tidak ada baris data yang ditemukan dalam berkas.");
            return Command::SUCCESS;
        }

        // Render table
        $tableHeaders = [
            'Pintu',
            'Pemilik',
            'Nama Penghuni',
            'No. Telepon',
            'Status',
            'Harga Sewa',
            'Tagihan (Raw)',
            'Tgl Masuk',
            'Tempo',
            'Terakhir Bayar',
            'Ket / Janji Bayar',
        ];

        $tableData = [];
        $warningList = [];

        foreach ($rows as $r) {
            $priceText = $r['base_price'] > 0
                ? 'Rp ' . number_format($r['base_price'], 0, ',', '.')
                : '-';

            $tagihanText = !empty($r['tagihan_raw'])
                ? $r['tagihan_raw']
                : ($r['tagihan_amount'] > 0 ? 'Rp ' . number_format($r['tagihan_amount'], 0, ',', '.') : '-');

            $statusColored = $r['status_kamar'] === 'terisi'
                ? ($r['is_unpaid'] ? '<fg=yellow>Terisi (BB)</>' : '<fg=green>Terisi</>')
                : '<fg=gray>Kosong (KS)</>';

            $tglMasukDisplay = $r['tanggal_masuk']
                ? $r['tanggal_masuk']
                : ($r['tanggal_masuk_raw'] ?: '-');

            $tableData[] = [
                $r['room_number'] ?: '-',
                StrLimit($r['pemilik'] ?: '-', 18),
                StrLimit($r['nama_kontak'] ?: '-', 20),
                $r['nomor_telepon'] ?: '-',
                $statusColored,
                $priceText,
                $tagihanText,
                $tglMasukDisplay,
                $r['tempo'] ? "Tgl {$r['tempo']}" : ($r['tempo_raw'] ?: '-'),
                $r['terakhir_bayar_raw'] ?: '-',
                StrLimit(($r['keterangan'] ? "{$r['keterangan']} " : '') . ($r['janji_bayar'] ?: ''), 20),
            ];

            if (!empty($r['warnings'])) {
                foreach ($r['warnings'] as $w) {
                    $warningList[] = "[Pintu {$r['room_number']}] {$w}";
                }
            }
        }

        $this->table($tableHeaders, $tableData);

        // Display summary
        $this->newLine();
        $this->info("📊 RINGKASAN HASIL PARSING:");
        $this->line(" • Total Unit Pintu : <info>{$summary['total']}</info>");
        $this->line(" • Unit Terisi      : <fg=green>{$summary['valid']}</>");
        $this->line(" • Unit Kosong (KS) : <fg=gray>{$summary['empty_rooms']}</>");
        $this->line(" • Belum Bayar (BB) : <fg=yellow>{$summary['unpaid']}</>");
        $this->line(" • Peringatan Audit : " . ($summary['warnings'] > 0 ? "<fg=red>{$summary['warnings']}</>" : "<fg=green>0</>"));

        if (!empty($warningList)) {
            $this->newLine();
            $this->warn("⚠️  CATATAN PERINGATAN VALIDASI:");
            foreach ($warningList as $w) {
                $this->line("  - {$w}");
            }
        }

        // If dry run, stop here
        if ($isDryRun) {
            $this->newLine();
            $this->comment("💡 INFORMASI: Mode --dry-run aktif. Tidak ada perubahan yang disimpan ke basis data.");
            $this->line("Untuk mengeksekusi impor data secara permanen, jalankan:");
            $this->line("  <info>php artisan import:tenants-from-excel \"{$filePath}\" --force</info>");
            return Command::SUCCESS;
        }

        // If not force, confirm with user
        if (!$isForce && !$this->confirm('Apakah Anda yakin ingin memasukkan data di atas ke basis data produksi?', false)) {
            $this->warn('Operasi impor dibatalkan oleh pengguna.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info("⏳ Menyimpan data ke basis data...");

        try {
            $res = $importService->executeImport($rows);

            $this->newLine();
            $this->info("✅ IMPOR DATA SELESAI DENGAN SUKSES!");
            $this->line(" • Properti Baru Dibuat    : <info>{$res['properties_created']}</info>");
            $this->line(" • Kamar Baru Dibuat       : <info>{$res['rooms_created']}</info>");
            $this->line(" • Kamar Diperbarui        : <info>{$res['rooms_updated']}</info>");
            $this->line(" • Akun Penghuni Dibuat    : <info>{$res['users_created']}</info>");
            $this->line(" • Sewa Aktif Dibuat       : <info>{$res['tenancies_created']}</info>");
            $this->line(" • Invoice Tagihan Diterbit: <info>{$res['invoices_created']}</info>");

            Log::info('[EXCEL IMPORT] Selesai impor data tenants dari: ' . $filePath, $res);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Gagal melakukan impor ke database: " . $e->getMessage());
            Log::error('[EXCEL IMPORT ERROR] ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return Command::FAILURE;
        }
    }
}

/**
 * Helper to truncate string with ellipsis.
 */
function StrLimit(string $val, int $limit): string
{
    if (mb_strlen($val) <= $limit) {
        return $val;
    }
    return mb_substr($val, 0, $limit - 2) . '..';
}
