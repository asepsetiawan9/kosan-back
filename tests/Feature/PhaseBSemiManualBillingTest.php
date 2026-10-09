<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BillingLog;
use App\Models\BillingTemplate;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseBSemiManualBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'must_change_password' => false,
        ]);

        $this->tenant = User::factory()->create([
            'role' => 'penyewa',
            'must_change_password' => false,
        ]);
    }

    private function createProperty(string $name = 'Kosan Barokah'): Property
    {
        return Property::create([
            'name' => $name,
            'address' => 'Jl. Mawar No. 12',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'owner_name' => 'Haji Sukri',
            'owner_phone' => '081234567890',
        ]);
    }

    private function createRoom(Property $property, string $roomNumber = '101', float $price = 1200000): Room
    {
        return Room::create([
            'property_id' => $property->id,
            'room_number' => $roomNumber,
            'name' => "Kamar {$roomNumber}",
            'type' => 'standar',
            'base_price' => $price,
            'status' => 'terisi',
        ]);
    }

    private function createTenancy(Room $room, User $user, array $overrides = []): Tenancy
    {
        return Tenancy::create(array_merge([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'tenant_name' => 'Penyewa Contoh',
            'tenant_phone' => '081234567890',
            'tenant_email' => 'penyewa@example.com',
            'start_date' => Carbon::now()->subMonths(1)->format('Y-m-d'),
            'billing_due_day' => Carbon::today()->day,
            'deposit_amount' => 500000,
            'deposit_status' => 'ditahan',
            'status' => 'aktif',
        ], $overrides));
    }

    public function test_guest_cannot_access_billing_endpoints(): void
    {
        $this->getJson('/api/admin/billing/summary')->assertStatus(401);
        $this->getJson('/api/admin/billing/targets')->assertStatus(401);
        $this->getJson('/api/admin/billing/templates')->assertStatus(401);
    }

    public function test_tenant_cannot_access_admin_billing_endpoints(): void
    {
        $this->actingAs($this->tenant)
            ->getJson('/api/admin/billing/summary')
            ->assertStatus(403);
    }

    public function test_admin_can_crud_billing_templates(): void
    {
        // 1. Create template
        $response = $this->actingAs($this->admin)->postJson('/api/admin/billing/templates', [
            'key' => 'custom_reminder',
            'title' => 'Pengingat Kustom',
            'body' => 'Halo {{nama}}, tagihan kos Kamar {{kamar}} sebesar Rp {{nominal}} mohon dibayar ya.',
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.key', 'custom_reminder')
            ->assertJsonPath('data.title', 'Pengingat Kustom');

        $templateId = $response->json('data.id');

        // 2. List templates
        $listResponse = $this->actingAs($this->admin)->getJson('/api/admin/billing/templates');
        $listResponse->assertStatus(200)
            ->assertJsonFragment(['key' => 'custom_reminder']);

        // 3. Update template
        $updateResponse = $this->actingAs($this->admin)->putJson("/api/admin/billing/templates/{$templateId}", [
            'title' => 'Pengingat Kustom Updated',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.title', 'Pengingat Kustom Updated');

        // 4. Delete template
        $deleteResponse = $this->actingAs($this->admin)->deleteJson("/api/admin/billing/templates/{$templateId}");
        $deleteResponse->assertStatus(200);

        $this->assertDatabaseMissing('billing_templates', ['id' => $templateId]);
    }

    public function test_admin_can_preview_template(): void
    {
        $template = BillingTemplate::create([
            'key' => 'preview_test',
            'title' => 'Test Preview',
            'body' => 'Halo {{nama}}, kamar {{kamar}} nominal Rp {{nominal}} jatuh tempo {{jatuh_tempo}}.',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/billing/templates/preview', [
            'template_id' => $template->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['rendered_msg', 'phone', 'wa_link'],
            ]);

        $rendered = $response->json('data.rendered_msg');
        $this->assertStringContainsString('Halo', $rendered);
        $this->assertStringContainsString('https://wa.me/', $response->json('data.wa_link'));
    }

    public function test_billing_targets_and_summary_calculation(): void
    {
        $property = $this->createProperty('Kosan Barokah');
        $room = $this->createRoom($property, '101', 1200000);
        $tenancy = $this->createTenancy($room, $this->tenant, [
            'tenant_name' => 'Ahmad Tenant',
            'tenant_phone' => '081298765432',
            'billing_due_day' => Carbon::today()->day,
        ]);

        $invoice = Invoice::create([
            'tenancy_id' => $tenancy->id,
            'invoice_number' => 'INV-2026-TEST',
            'period' => 'Oktober 2026',
            'total_amount' => 1200000,
            'paid_amount' => 0,
            'status' => 'belum_bayar',
            'due_date' => Carbon::today()->format('Y-m-d'),
        ]);

        // Test Targets
        $targetsResponse = $this->actingAs($this->admin)->getJson('/api/admin/billing/targets');
        $targetsResponse->assertStatus(200)
            ->assertJsonFragment([
                'tenancy_id' => $tenancy->id,
                'tenant_name' => 'Ahmad Tenant',
                'tenant_phone' => '081298765432',
                'room_number' => '101',
                'days_until_due' => 0,
            ]);

        // Test Summary
        $summaryResponse = $this->actingAs($this->admin)->getJson('/api/admin/billing/summary');
        $summaryResponse->assertStatus(200)
            ->assertJsonPath('data.jatuh_tempo_hari_ini', 1)
            ->assertJsonPath('data.total_target', 1);
    }

    public function test_generate_whatsapp_link_and_bulk_links(): void
    {
        $property = $this->createProperty('Griya Indah');
        $room = $this->createRoom($property, '202', 1500000);
        $tenancy = $this->createTenancy($room, $this->tenant, [
            'tenant_name' => 'Siti Aminah',
            'tenant_phone' => '087812345678',
            'billing_due_day' => 10,
        ]);

        $template = BillingTemplate::create([
            'key' => 'link_test',
            'title' => 'Link Test',
            'body' => 'Tagihan kos {{nama}} kamar {{kamar}} nominal Rp {{nominal}}',
            'is_active' => true,
        ]);

        // Single link generation
        $singleResponse = $this->actingAs($this->admin)->postJson('/api/admin/billing/generate-link', [
            'tenancy_id' => $tenancy->id,
            'template_id' => $template->id,
        ]);

        $singleResponse->assertStatus(200)
            ->assertJsonPath('data.tenancy_id', $tenancy->id)
            ->assertJsonPath('data.phone', '087812345678');

        $waLink = $singleResponse->json('data.wa_link');
        $this->assertStringStartsWith('https://wa.me/6287812345678?text=', $waLink);
        $this->assertStringContainsString('Siti%20Aminah', $waLink);

        // Bulk links generation
        $bulkResponse = $this->actingAs($this->admin)->postJson('/api/admin/billing/bulk-links', [
            'tenancy_ids' => [$tenancy->id],
            'template_id' => $template->id,
        ]);

        $bulkResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenancy_id', $tenancy->id);
    }

    public function test_admin_can_log_billing_and_view_history(): void
    {
        $property = $this->createProperty('Kosan Melati');
        $room = $this->createRoom($property, '303', 1000000);
        $tenancy = $this->createTenancy($room, $this->tenant);

        $template = BillingTemplate::create([
            'key' => 'log_test',
            'title' => 'Log Test',
            'body' => 'Sample body',
            'is_active' => true,
        ]);

        // 1. Create Log
        $logResponse = $this->actingAs($this->admin)->postJson('/api/admin/billing/log', [
            'tenancy_id' => $tenancy->id,
            'template_id' => $template->id,
            'rendered_msg' => 'Pesan tagihan yang sudah dikirim via WA Web',
            'phone_target' => '081234567890',
            'channel' => 'wa_web',
        ]);

        $logResponse->assertStatus(201)
            ->assertJsonPath('data.tenancy_id', $tenancy->id)
            ->assertJsonPath('data.channel', 'wa_web');

        $this->assertDatabaseHas('billing_logs', [
            'tenancy_id' => $tenancy->id,
            'phone_target' => '081234567890',
            'admin_id' => $this->admin->id,
        ]);

        // 2. View History
        $historyResponse = $this->actingAs($this->admin)->getJson('/api/admin/billing/history');
        $historyResponse->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.tenancy_id', $tenancy->id);
    }
}
