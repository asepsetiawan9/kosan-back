<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\TenantExcelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseDExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $sampleExcelPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'must_change_password' => false,
        ]);

        $this->sampleExcelPath = base_path('../data_penghuni_kos_ai_ready.xlsx');
    }

    public function test_import_command_fails_when_file_not_found(): void
    {
        $this->artisan('import:tenants-from-excel', [
            'filepath' => 'non_existent_file.xlsx',
        ])
            ->expectsOutputToContain('Berkas Excel tidak ditemukan')
            ->assertExitCode(1);
    }

    public function test_import_command_dry_run_does_not_modify_database(): void
    {
        if (!file_exists($this->sampleExcelPath)) {
            $this->markTestSkipped('Sample file not found at: ' . $this->sampleExcelPath);
        }

        $this->artisan('import:tenants-from-excel', [
            'filepath' => $this->sampleExcelPath,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN (Simulasi Tanpa Simpan)')
            ->expectsOutputToContain('P01')
            ->expectsOutputToContain('Mode --dry-run aktif')
            ->assertExitCode(0);

        // Verify database is completely untouched
        $this->assertEquals(0, Room::count());
        $this->assertEquals(0, Tenancy::count());
    }

    public function test_import_service_parses_sample_excel_correctly(): void
    {
        if (!file_exists($this->sampleExcelPath)) {
            $this->markTestSkipped('Sample file not found at: ' . $this->sampleExcelPath);
        }

        $service = new TenantExcelImportService();
        $result = $service->parseFile($this->sampleExcelPath);

        $this->assertNotEmpty($result['rows']);
        $this->assertEquals(3, $result['summary']['total']);
        $this->assertEquals(3, $result['summary']['valid']);

        $firstRow = $result['rows'][0];
        $this->assertEquals('P01', $firstRow['room_number']);
        $this->assertEquals('H. KASMIYANTO', $firstRow['pemilik']);
        $this->assertEquals('Rizal Maulana Yusuf', $firstRow['nama_kontak']);
        $this->assertEquals('6281322255495', $firstRow['nomor_telepon']);
        $this->assertEquals(500000.0, $firstRow['base_price']);
        $this->assertEquals('2026-02-11', $firstRow['tanggal_masuk']);
        $this->assertEquals(11, $firstRow['tempo']);
        $this->assertEquals('10-Agu', $firstRow['terakhir_bayar_raw']);
    }

    public function test_import_service_executes_import_and_creates_records(): void
    {
        if (!file_exists($this->sampleExcelPath)) {
            $this->markTestSkipped('Sample file not found at: ' . $this->sampleExcelPath);
        }

        $service = new TenantExcelImportService();
        $parsed = $service->parseFile($this->sampleExcelPath);
        $res = $service->executeImport($parsed['rows']);

        $this->assertGreaterThan(0, $res['properties_created']);
        $this->assertEquals(3, $res['rooms_created']);
        $this->assertEquals(3, $res['users_created']);
        $this->assertEquals(3, $res['tenancies_created']);

        // Verify Room and Tenancy created
        $room = Room::where('room_number', 'P01')->first();
        $this->assertNotNull($room);
        $this->assertEquals('terisi', $room->status);
        $this->assertEquals(500000.0, (float) $room->base_price);

        $tenancy = Tenancy::where('room_id', $room->id)->first();
        $this->assertNotNull($tenancy);
        $this->assertEquals('Rizal Maulana Yusuf', $tenancy->tenant_name);
        $this->assertEquals(11, $tenancy->billing_due_day);
        $this->assertEquals('2026-02-11', $tenancy->start_date->format('Y-m-d'));
    }

    public function test_import_handles_empty_room_ks_and_unpaid_bb(): void
    {
        $mockRows = [
            [
                'row_index' => 1,
                'room_number' => 'P05',
                'pemilik' => 'ENDANG TRI WURYANI',
                'nama_kontak' => '',
                'nomor_telepon' => '',
                'status_kamar' => 'kosong',
                'is_empty_room' => true,
                'is_unpaid' => false,
                'base_price' => 500000.0,
                'tagihan_amount' => 0.0,
                'tanggal_masuk' => null,
                'tempo' => null,
                'lokasi' => 'Belakang Rumah',
                'keterangan' => '',
            ],
            [
                'row_index' => 2,
                'room_number' => 'P04',
                'pemilik' => 'ENDANG TRI WURYANI',
                'nama_kontak' => 'Dendi Angga Firmansyah',
                'nomor_telepon' => '6285847732875',
                'status_kamar' => 'terisi',
                'is_empty_room' => false,
                'is_unpaid' => true,
                'base_price' => 600000.0,
                'tagihan_amount' => 600000.0,
                'tanggal_masuk' => '2024-05-07',
                'tempo' => 7,
                'lokasi' => 'Cijingga, Bodong',
                'keterangan' => 'Ags',
            ],
        ];

        $service = new TenantExcelImportService();
        $res = $service->executeImport($mockRows);

        $this->assertEquals(2, $res['rooms_created']);
        $this->assertEquals(1, $res['tenancies_created']);
        $this->assertEquals(1, $res['invoices_created']);

        // Empty room verification
        $emptyRoom = Room::where('room_number', 'P05')->first();
        $this->assertNotNull($emptyRoom);
        $this->assertEquals('kosong', $emptyRoom->status);
        $this->assertNull(Tenancy::where('room_id', $emptyRoom->id)->first());

        // Unpaid room & invoice verification
        $unpaidRoom = Room::where('room_number', 'P04')->first();
        $this->assertNotNull($unpaidRoom);
        $this->assertEquals('terisi', $unpaidRoom->status);

        $tenancy = Tenancy::where('room_id', $unpaidRoom->id)->first();
        $this->assertNotNull($tenancy);
        $this->assertEquals('Dendi Angga Firmansyah', $tenancy->tenant_name);

        $invoice = Invoice::where('tenancy_id', $tenancy->id)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals('2026-08', $invoice->period);
        $this->assertEquals(600000.0, (float) $invoice->total_amount);
        $this->assertEquals('belum_bayar', $invoice->status);
    }
}
