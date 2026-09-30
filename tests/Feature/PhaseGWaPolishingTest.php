<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaTemplate;
use App\Services\WaHealthCheckService;
use Database\Seeders\WaTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PhaseGWaPolishingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenant;
    protected Room $room;
    protected Tenancy $tenancy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WaTemplateSeeder::class);

        // Admin User
        $this->admin = User::factory()->create([
            'email' => 'admin@kosan.com',
            'role' => 'admin',
        ]);

        // Tenant User
        $this->tenant = User::factory()->create([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '081234567890',
            'wa_number' => '6281234567890',
            'role' => 'penyewa',
            'wa_opt_in' => true,
        ]);

        $property = Property::create([
            'name' => 'Kosan Melati Indah',
            'address' => 'Jl. Mawar No. 12',
            'city' => 'Jakarta Selatan',
            'province' => 'DKI Jakarta',
            'owner_name' => 'Haji Lulung',
            'owner_phone' => '081122334455',
            'managed_by' => $this->admin->id,
        ]);

        $this->room = Room::create([
            'property_id' => $property->id,
            'room_number' => 'A-101',
            'name' => 'Kamar Melati A1',
            'type' => 'standar',
            'base_price' => 1500000,
            'status' => 'terisi',
        ]);

        $this->tenancy = Tenancy::create([
            'room_id' => $this->room->id,
            'user_id' => $this->tenant->id,
            'tenant_name' => $this->tenant->name,
            'tenant_phone' => $this->tenant->phone,
            'tenant_email' => $this->tenant->email,
            'rent_amount' => 1500000,
            'start_date' => now()->subMonths(2)->format('Y-m-d'),
            'billing_due_day' => 10,
            'deposit_amount' => 500000,
            'deposit_status' => 'ditahan',
            'status' => 'aktif',
        ]);
    }

    public function test_health_check_endpoint_accessible_by_admin(): void
    {
        $token = $this->admin->createToken('admin-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/wa/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'timestamp',
                    'execution_time_ms',
                    'checks' => [
                        'provider' => ['status', 'connected', 'provider', 'message'],
                        'database' => ['status', 'driver', 'latency_ms'],
                        'queue' => ['status', 'pending_jobs', 'failed_jobs', 'queued_messages'],
                        'messages' => ['status', 'sent_last_24h', 'failed_last_24h', 'failure_rate_percent'],
                        'scheduler' => ['status', 'active_rules_count'],
                    ],
                    'system' => ['app_env', 'timezone', 'php_version', 'laravel_version'],
                ],
            ]);

        $this->assertContains($response->json('data.status'), ['healthy', 'degraded', 'unhealthy']);
    }

    public function test_public_health_check_ping_returns_status(): void
    {
        $response = $this->getJson('/api/health/wa');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'checks',
            ]);
    }

    public function test_wa_cleanup_payloads_cleans_payloads_older_than_n_days(): void
    {
        // Message 1: Old (100 days ago) with payload
        $oldMsg = WaMessage::create([
            'direction' => 'in',
            'phone' => '6281234567890',
            'body' => 'bukti transfer',
            'status' => 'processed',
            'raw_payload' => ['event' => 'message', 'data' => 'dummy payload'],
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        // Message 2: Recent (5 days ago) with payload
        $recentMsg = WaMessage::create([
            'direction' => 'in',
            'phone' => '6281234567890',
            'body' => 'tagihan',
            'status' => 'processed',
            'raw_payload' => ['event' => 'message', 'data' => 'keep this'],
        ]);

        WaMessage::where('id', $oldMsg->id)->update(['created_at' => now()->subDays(100)]);
        WaMessage::where('id', $recentMsg->id)->update(['created_at' => now()->subDays(5)]);

        $this->assertNotNull($oldMsg->fresh()->raw_payload);
        $this->assertNotNull($recentMsg->fresh()->raw_payload);

        // Run cleanup with days=90
        $exitCode = Artisan::call('wa:cleanup-payloads', ['--days' => 90]);
        $this->assertSame(0, $exitCode);

        // Old message raw_payload should be NULL
        $this->assertNull($oldMsg->fresh()->raw_payload);

        // Recent message raw_payload should still exist
        $this->assertNotNull($recentMsg->fresh()->raw_payload);
    }

    public function test_admin_new_proof_notification_is_sent_when_admin_notify_number_configured(): void
    {
        Config::set('services.whatsapp.admin_notify_number', '628999888777');
        Config::set('services.whatsapp.webhook_secret', 'secret123');

        $invoice = Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-2026-09-001',
            'period' => 'September 2026',
            'total_amount' => 1500000,
            'paid_amount' => 0,
            'due_date' => now()->addDays(5)->format('Y-m-d'),
            'status' => 'belum_bayar',
        ]);

        // Post simulated webhook sending an image
        $payload = [
            'sender' => '6281234567890',
            'message' => 'bukti transfer sewa',
            'url' => 'https://fake-fonnte-storage.test/proof.jpg',
            'id' => 'fonnte_msg_proof_test_' . uniqid(),
        ];

        $response = $this->withHeader('X-Webhook-Secret', 'secret123')
            ->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200);

        // Verify that an outbound message was queued for the admin notification number
        $adminNotificationMsg = WaMessage::where('direction', 'out')
            ->where('phone', '628999888777')
            ->where('template_key', 'admin_new_proof')
            ->first();

        $this->assertNotNull($adminNotificationMsg, 'Admin notification wa_message must be created.');
        $this->assertStringContainsString('Budi Santoso', $adminNotificationMsg->body);
        $this->assertStringContainsString('A-101', $adminNotificationMsg->body);
    }

    public function test_tenant_can_send_aduan_command_and_receive_complaint_history(): void
    {
        Config::set('services.whatsapp.webhook_secret', 'secret123');

        // Create an existing complaint for the tenant
        Complaint::create([
            'tenancy_id' => $this->tenancy->id,
            'category' => 'fasilitas_rusak',
            'description' => 'Keran wastafel bocor',
            'status' => 'diproses',
        ]);

        $payload = [
            'sender' => '6281234567890',
            'message' => 'aduan',
            'id' => 'fonnte_msg_aduan_' . uniqid(),
        ];

        $response = $this->withHeader('X-Webhook-Secret', 'secret123')
            ->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200);

        $reply = WaMessage::where('direction', 'out')
            ->where('phone', '6281234567890')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringContainsString('layanan informasi aduan', $reply->body);
        $this->assertStringContainsString('Keran wastafel bocor', $reply->body);
        $this->assertStringContainsString('/portal/complaints/new', $reply->body);
    }

    public function test_tenant_can_send_status_alias_for_tagihan(): void
    {
        Config::set('services.whatsapp.webhook_secret', 'secret123');

        Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-STATUS-001',
            'period' => 'Oktober 2026',
            'total_amount' => 1500000,
            'paid_amount' => 0,
            'due_date' => now()->addDays(3)->format('Y-m-d'),
            'status' => 'belum_bayar',
        ]);

        $payload = [
            'sender' => '6281234567890',
            'message' => 'status',
            'id' => 'fonnte_msg_status_' . uniqid(),
        ];

        $response = $this->withHeader('X-Webhook-Secret', 'secret123')
            ->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200);

        $reply = WaMessage::where('direction', 'out')
            ->where('phone', '6281234567890')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Oktober 2026', $reply->body);
        $this->assertStringContainsString('Rp 1.500.000', $reply->body);
    }

    public function test_bot_help_template_includes_all_commands(): void
    {
        $template = WaTemplate::where('key', 'bot_help')->first();
        $this->assertNotNull($template);
        $this->assertStringContainsString('tagihan', $template->body);
        $this->assertStringContainsString('aduan', $template->body);
        $this->assertStringContainsString('stop', $template->body);
        $this->assertStringContainsString('mulai', $template->body);
    }

    public function test_wa_expire_conversations_command_resets_stale_conversations(): void
    {
        $conv = WaConversation::create([
            'phone' => '6281234567890',
            'tenant_id' => $this->tenant->id,
            'state' => 'awaiting_invoice_choice',
            'context' => ['invoice_ids' => ['inv1', 'inv2']],
            'expires_at' => now()->subMinutes(10), // expired
        ]);

        $exitCode = Artisan::call('wa:expire-conversations');
        $this->assertSame(0, $exitCode);

        $fresh = $conv->fresh();
        $this->assertSame('idle', $fresh->state);
        $this->assertEmpty($fresh->context);
    }

    public function test_health_check_service_reflects_degraded_when_failed_messages_exist(): void
    {
        // Insert 6 failed messages in 24h to trigger high failure rate alert (threshold is >= 5)
        for ($i = 0; $i < 6; $i++) {
            WaMessage::create([
                'direction' => 'out',
                'phone' => '6281234567890',
                'body' => 'Pesan uji kegagalan ' . $i,
                'status' => 'failed',
                'error_message' => 'Connection timeout',
                'created_at' => now()->subHours(2),
            ]);
        }

        $service = app(WaHealthCheckService::class);
        $result = $service->checkHealth();

        $this->assertSame('degraded', $result['status']);
        $this->assertTrue($result['checks']['messages']['high_failure_alert']);
        $this->assertGreaterThanOrEqual(6, $result['checks']['messages']['failed_last_24h']);
    }
}
