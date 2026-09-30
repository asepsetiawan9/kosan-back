<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\WaMessage;
use App\Models\WaReminderLog;
use App\Models\WaReminderRule;
use App\Models\WaTemplate;
use App\Services\WaReminderService;
use Carbon\Carbon;
use Database\Seeders\WaReminderRuleSeeder;
use Database\Seeders\WaTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseEWaRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenantUser;
    protected Property $property;
    protected Room $room;
    protected Tenancy $tenancy;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.provider' => 'fake']);

        $this->seed(WaTemplateSeeder::class);
        $this->seed(WaReminderRuleSeeder::class);

        $this->admin = User::create([
            'name' => 'Admin Kosan',
            'email' => 'admin@kosan.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'phone' => '081234567890',
            'wa_number' => '6281234567890',
            'wa_opt_in' => true,
        ]);

        $this->tenantUser = User::create([
            'name' => 'Budi Penyewa',
            'email' => 'budi@gmail.com',
            'password' => bcrypt('password123'),
            'role' => 'penyewa',
            'phone' => '081987654321',
            'wa_number' => '6281987654321',
            'wa_opt_in' => true,
        ]);

        $this->property = Property::create([
            'name' => 'Kos Melati Residence',
            'address' => 'Jl. Melati No. 12',
            'city' => 'Bandung',
            'owner_name' => 'Haji Asep',
            'owner_phone' => '081299998888',
        ]);

        $this->room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '101',
            'name' => 'Kamar Standard 101',
            'type' => 'standar',
            'base_price' => 1200000.00,
            'status' => 'terisi',
        ]);

        $this->tenancy = Tenancy::create([
            'room_id' => $this->room->id,
            'user_id' => $this->tenantUser->id,
            'tenant_name' => $this->tenantUser->name,
            'tenant_phone' => $this->tenantUser->phone,
            'tenant_email' => $this->tenantUser->email,
            'start_date' => Carbon::now()->subMonths(2),
            'end_date' => Carbon::now()->addMonths(4),
            'rent_amount' => 1200000.00,
            'billing_due_day' => 10,
            'status' => 'aktif',
        ]);
    }

    public function test_admin_can_list_templates_with_placeholders(): void
    {
        $token = $this->admin->createToken('admin', ['role:admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/wa/templates');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'key', 'title', 'body', 'is_active'],
                ],
                'placeholders',
            ]);

        $this->assertNotEmpty($response->json('data'));
        $this->assertArrayHasKey('nama', $response->json('placeholders'));
    }

    public function test_admin_can_update_wa_template(): void
    {
        $template = WaTemplate::where('key', 'reminder_h_minus_1')->firstOrFail();
        $token = $this->admin->createToken('admin', ['role:admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/wa/templates/{$template->id}", [
                'title' => 'Pengingat Tagihan H-1 Custom',
                'body' => 'Halo {{nama}}, besok tagihan kamar {{kamar}} sebesar {{nominal}} jatuh tempo.',
                'is_active' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Pengingat Tagihan H-1 Custom');

        $this->assertDatabaseHas('wa_templates', [
            'id' => $template->id,
            'title' => 'Pengingat Tagihan H-1 Custom',
        ]);
    }

    public function test_admin_can_preview_template(): void
    {
        $token = $this->admin->createToken('admin', ['role:admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/wa/templates/preview', [
                'body' => 'Halo {{nama}}, tagihan {{kamar}} sebesar {{nominal}} jatuh tempo pada {{jatuh_tempo}}.',
                'params' => [
                    'nama' => 'Joko',
                    'kamar' => '201',
                    'nominal' => 'Rp 2.000.000',
                    'jatuh_tempo' => '10/10/2026',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('rendered', 'Halo Joko, tagihan 201 sebesar Rp 2.000.000 jatuh tempo pada 10/10/2026.');
    }

    public function test_admin_can_list_and_manage_reminder_rules(): void
    {
        $token = $this->admin->createToken('admin', ['role:admin'])->plainTextToken;

        // 1. List rules
        $listRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/wa/reminder-rules');

        $listRes->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'trigger_type', 'offset_days', 'send_time', 'template_key', 'is_active'],
                ],
                'summary' => ['total_rules', 'active_rules'],
            ]);

        // 2. Create new rule
        $createRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/wa/reminder-rules', [
                'name' => 'Pengingat H-3 Sebelum Jatuh Tempo',
                'trigger_type' => 'before_due',
                'offset_days' => 3,
                'send_time' => '08:30',
                'template_key' => 'reminder_h_minus_3',
                'is_active' => true,
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.name', 'Pengingat H-3 Sebelum Jatuh Tempo');

        $newRuleId = $createRes->json('data.id');

        // 3. Toggle active
        $toggleRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/wa/reminder-rules/{$newRuleId}/toggle");

        $toggleRes->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        // 4. Update rule
        $updateRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/wa/reminder-rules/{$newRuleId}", [
                'name' => 'Pengingat H-3 Updated',
                'offset_days' => 3,
            ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Pengingat H-3 Updated');

        // 5. Delete rule
        $deleteRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/wa/reminder-rules/{$newRuleId}");

        $deleteRes->assertStatus(200);
        $this->assertDatabaseMissing('wa_reminder_rules', ['id' => $newRuleId]);
    }

    public function test_reminder_engine_sends_on_h_minus_1(): void
    {
        $today = Carbon::today('Asia/Jakarta');
        $dueDate = $today->copy()->addDay()->toDateString(); // Tomorrow is due date (H-1 today)

        $invoice = Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-TEST-H-MINUS-1',
            'period' => '2026-10',
            'total_amount' => 1200000.00,
            'paid_amount' => 0.00,
            'status' => 'belum_bayar',
            'due_date' => $dueDate,
        ]);

        /** @var WaReminderService $service */
        $service = app(WaReminderService::class);
        $result = $service->runReminders(false, $today);

        $this->assertEquals(1, $result['reminders_sent']);

        $this->assertDatabaseHas('wa_reminder_logs', [
            'invoice_id' => $invoice->id,
        ]);
        $log = WaReminderLog::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals($today->toDateString(), $log->sent_for_date->toDateString());

        $this->assertDatabaseHas('wa_messages', [
            'phone' => '6281987654321',
            'template_key' => 'reminder_h_minus_1',
            'status' => 'sent',
        ]);
    }

    public function test_reminder_engine_deduplication_prevents_duplicate_sends(): void
    {
        $today = Carbon::today('Asia/Jakarta');
        $dueDate = $today->toDateString(); // Due today (on_due rule)

        $invoice = Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-TEST-ON-DUE',
            'period' => '2026-10',
            'total_amount' => 1200000.00,
            'paid_amount' => 0.00,
            'status' => 'belum_bayar',
            'due_date' => $dueDate,
        ]);

        /** @var WaReminderService $service */
        $service = app(WaReminderService::class);

        // Run 1: Should send
        $result1 = $service->runReminders(false, $today);
        $this->assertEquals(1, $result1['reminders_sent']);

        // Run 2: Same day, should skip and NOT send duplicate
        $result2 = $service->runReminders(false, $today);
        $this->assertEquals(0, $result2['reminders_sent']);
        $this->assertEquals(1, $result2['skipped']['already_sent']);

        $countMessages = WaMessage::where('phone', '6281987654321')->count();
        $this->assertEquals(1, $countMessages);
    }

    public function test_reminder_engine_skips_invoice_with_pending_payment(): void
    {
        $today = Carbon::today('Asia/Jakarta');
        $dueDate = $today->copy()->subDays(5)->toDateString(); // 5 days past due (H+5 rule)

        $invoice = Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-TEST-H-PLUS-5',
            'period' => '2026-10',
            'total_amount' => 1200000.00,
            'paid_amount' => 0.00,
            'status' => 'terlambat',
            'due_date' => $dueDate,
        ]);

        // Tenant already uploaded payment proof and it is pending verification
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 1200000.00,
            'method' => 'manual_transfer',
            'proof_file' => 'private/payment_proofs/test.jpg',
            'status' => 'pending',
        ]);

        /** @var WaReminderService $service */
        $service = app(WaReminderService::class);
        $result = $service->runReminders(false, $today);

        // Should NOT send reminder because payment proof is pending
        $this->assertEquals(0, $result['reminders_sent']);
        $this->assertEquals(1, $result['skipped']['pending_payment']);

        $this->assertDatabaseMissing('wa_reminder_logs', [
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_reminder_engine_skips_opted_out_tenant(): void
    {
        $this->tenantUser->update(['wa_opt_in' => false]);

        $today = Carbon::today('Asia/Jakarta');
        $dueDate = $today->copy()->subDays(10)->toDateString(); // H+10 rule

        $invoice = Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-TEST-OPT-OUT',
            'period' => '2026-10',
            'total_amount' => 1200000.00,
            'paid_amount' => 0.00,
            'status' => 'terlambat',
            'due_date' => $dueDate,
        ]);

        /** @var WaReminderService $service */
        $service = app(WaReminderService::class);
        $result = $service->runReminders(false, $today);

        $this->assertEquals(0, $result['reminders_sent']);
        $this->assertEquals(1, $result['skipped']['opted_out']);
    }

    public function test_reminder_engine_dry_run_simulation_and_api_endpoints(): void
    {
        $today = Carbon::today('Asia/Jakarta');
        $dueDate = $today->copy()->subDays(15)->toDateString(); // H+15 warning

        $invoice = Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-TEST-H-PLUS-15',
            'period' => '2026-10',
            'total_amount' => 1200000.00,
            'paid_amount' => 0.00,
            'status' => 'terlambat',
            'due_date' => $dueDate,
        ]);

        $token = $this->admin->createToken('admin', ['role:admin'])->plainTextToken;

        // 1. Dry run via API
        $dryRunRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/wa/reminders/dry-run', [
                'date' => $today->toDateString(),
            ]);

        $dryRunRes->assertStatus(200)
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.items.0.status', 'ready');

        // No logs should be recorded on dry run
        $this->assertDatabaseMissing('wa_reminder_logs', [
            'invoice_id' => $invoice->id,
        ]);

        // 2. Real run via API
        $runRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/wa/reminders/run', [
                'date' => $today->toDateString(),
            ]);

        $runRes->assertStatus(200)
            ->assertJsonPath('data.dry_run', false)
            ->assertJsonPath('data.reminders_sent', 1);

        $this->assertDatabaseHas('wa_reminder_logs', [
            'invoice_id' => $invoice->id,
        ]);
        $savedLog = WaReminderLog::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($savedLog);
        $this->assertEquals($today->toDateString(), $savedLog->sent_for_date->toDateString());

        // 3. View reminder logs API
        $logsRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/wa/reminder-logs');

        $logsRes->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'invoice_id', 'invoice_number', 'tenant_name', 'rule_name', 'template_key'],
                ],
            ]);
    }

    public function test_artisan_commands_run_reminders_successfully(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        $this->artisan('wa:run-reminders --dry-run')
            ->assertExitCode(0);

        $this->artisan("wa:run-reminders --date={$today}")
            ->assertExitCode(0);
    }
}
