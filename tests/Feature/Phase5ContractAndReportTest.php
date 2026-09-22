<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Contract;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase5ContractAndReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenantUser;
    protected User $otherTenant;
    protected Room $room;
    protected Tenancy $tenancy;
    protected Tenancy $otherTenancy;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        $this->admin = User::factory()->create([
            'email' => 'admin@kosanpro.id',
            'role' => 'admin',
            'must_change_password' => false,
        ]);

        $this->tenantUser = User::factory()->create([
            'email' => 'ahmad@example.com',
            'phone' => '081234567890',
            'role' => 'penyewa',
            'must_change_password' => false,
        ]);

        $this->otherTenant = User::factory()->create([
            'email' => 'siti@example.com',
            'phone' => '089876543210',
            'role' => 'penyewa',
            'must_change_password' => false,
        ]);

        $this->room = Room::create([
            'room_number' => '101',
            'name' => 'Kamar Deluxe 101',
            'type' => 'deluxe',
            'base_price' => 1500000.00,
            'status' => 'terisi',
        ]);

        $facility = Facility::create(['name' => 'AC Panasonic', 'icon' => 'wind']);
        $this->room->facilities()->attach($facility->id);

        $this->tenancy = Tenancy::create([
            'room_id' => $this->room->id,
            'user_id' => $this->tenantUser->id,
            'tenant_name' => 'Ahmad Santoso',
            'tenant_phone' => '081234567890',
            'tenant_email' => 'ahmad@example.com',
            'start_date' => Carbon::now()->startOfMonth()->toDateString(),
            'end_date' => Carbon::now()->addYear()->toDateString(),
            'billing_due_day' => 10,
            'deposit_amount' => 500000.00,
            'deposit_status' => 'ditahan',
            'status' => 'aktif',
        ]);

        $otherRoom = Room::create([
            'room_number' => '102',
            'name' => 'Kamar Standar 102',
            'type' => 'standar',
            'base_price' => 1000000.00,
            'status' => 'terisi',
        ]);

        $this->otherTenancy = Tenancy::create([
            'room_id' => $otherRoom->id,
            'user_id' => $this->otherTenant->id,
            'tenant_name' => 'Siti Aminah',
            'tenant_phone' => '089876543210',
            'tenant_email' => 'siti@example.com',
            'start_date' => Carbon::now()->startOfMonth()->toDateString(),
            'end_date' => Carbon::now()->addYear()->toDateString(),
            'billing_due_day' => 15,
            'deposit_amount' => 300000.00,
            'deposit_status' => 'ditahan',
            'status' => 'aktif',
        ]);
    }

    public function test_admin_can_generate_contract_draft_successfully(): void
    {
        Sanctum::actingAs($this->admin, ['role:admin']);

        $response = $this->postJson("/api/admin/tenancies/{$this->tenancy->id}/contract");

        $response->assertStatus(201)
            ->assertJsonPath('data.tenancy_id', $this->tenancy->id)
            ->assertJsonPath('data.status', 'dikirim')
            ->assertJsonPath('data.is_signed', false);

        $this->assertDatabaseHas('contracts', [
            'tenancy_id' => $this->tenancy->id,
            'status' => 'dikirim',
        ]);

        $contract = Contract::where('tenancy_id', $this->tenancy->id)->first();
        $this->assertNotNull($contract);
        Storage::disk('local')->assertExists($contract->file_path);
    }

    public function test_tenant_can_view_their_own_contract_draft(): void
    {
        // Generate draft by admin
        Sanctum::actingAs($this->admin, ['role:admin']);
        $this->postJson("/api/admin/tenancies/{$this->tenancy->id}/contract");

        // Tenant views contract
        Sanctum::actingAs($this->tenantUser, ['role:penyewa']);
        $response = $this->getJson('/api/tenant/contract');

        $response->assertStatus(200)
            ->assertJsonPath('data.tenancy_id', $this->tenancy->id)
            ->assertJsonPath('data.status', 'dikirim')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'contract_number',
                    'status',
                    'stream_url',
                    'tenancy' => [
                        'tenant_name',
                        'room' => ['room_number', 'type'],
                    ],
                ],
            ]);
    }

    public function test_tenant_can_digitally_sign_contract_and_freeze_immutable(): void
    {
        // 1. Generate contract draft
        Sanctum::actingAs($this->admin, ['role:admin']);
        $this->postJson("/api/admin/tenancies/{$this->tenancy->id}/contract");

        // 2. Tenant signs contract
        Sanctum::actingAs($this->tenantUser, ['role:penyewa']);

        // Dummy 1x1 base64 transparent PNG
        $dummySig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $response = $this->postJson('/api/tenant/contract/sign', [
            'signature' => $dummySig,
            'agree_terms' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'ditandatangani')
            ->assertJsonPath('data.is_signed', true);

        $contract = Contract::where('tenancy_id', $this->tenancy->id)->first();
        $this->assertNotNull($contract);
        $this->assertNotNull($contract->signed_at);
        $this->assertNotNull($contract->signed_file_path);
        $this->assertNotNull($contract->signature_image);

        Storage::disk('local')->assertExists($contract->signed_file_path);
        Storage::disk('local')->assertExists($contract->signature_image);

        // Dispatched WhatsApp notification
        Queue::assertPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_immutability_safeguard_contract_cannot_be_resigned(): void
    {
        // 1. Generate & sign
        Sanctum::actingAs($this->admin, ['role:admin']);
        $this->postJson("/api/admin/tenancies/{$this->tenancy->id}/contract");

        Sanctum::actingAs($this->tenantUser, ['role:penyewa']);
        $dummySig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $this->postJson('/api/tenant/contract/sign', [
            'signature' => $dummySig,
            'agree_terms' => true,
        ])->assertStatus(200);

        // 2. Attempting to sign again must be rejected (HTTP 403 / 422 Policy Safeguard)
        $reSignResponse = $this->postJson('/api/tenant/contract/sign', [
            'signature' => $dummySig,
            'agree_terms' => true,
        ]);

        $this->assertTrue(in_array($reSignResponse->status(), [403, 422], true));
    }

    public function test_admin_financial_income_report_cash_basis_calculation(): void
    {
        // Create an invoice with items
        $invoice = Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-202609-001',
            'period' => Carbon::now()->format('Y-m'),
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(7)->toDateString(),
            'total_amount' => 2000000.00,
            'status' => 'sebagian_dibayar',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Sewa Kamar 101',
            'amount' => 1500000.00,
            'item_type' => 'sewa',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Uang Jaminan Deposit',
            'amount' => 500000.00,
            'item_type' => 'deposit',
        ]);

        // Record a successful payment of 1,500,000
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 1500000.00,
            'method' => 'manual_transfer',
            'status' => 'success',
            'verified_at' => Carbon::now(),
            'verified_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin, ['role:admin']);

        $response = $this->getJson('/api/admin/reports/income');

        $response->assertStatus(200)
            ->assertJsonPath('data.metrics.total_income', 1500000)
            ->assertJsonPath('data.metrics.pending_receivables', 500000)
            ->assertJsonPath('data.metrics.occupancy_rate', 100)
            ->assertJsonStructure([
                'data' => [
                    'period' => ['month', 'year', 'label'],
                    'metrics' => [
                        'total_income',
                        'pending_receivables',
                        'occupancy_rate',
                        'total_rooms',
                        'occupied_rooms',
                    ],
                    'category_breakdown',
                    'cashflow_trend',
                ],
            ]);
    }

    public function test_admin_can_export_financial_report_to_pdf_and_excel(): void
    {
        Sanctum::actingAs($this->admin, ['role:admin']);

        // Export PDF
        $pdfResponse = $this->get('/api/admin/reports/export?format=pdf');
        $pdfResponse->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');

        // Export Excel
        $excelResponse = $this->get('/api/admin/reports/export?format=excel');
        $excelResponse->assertStatus(200);
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $excelResponse->headers->get('content-type')
        );
    }
}
