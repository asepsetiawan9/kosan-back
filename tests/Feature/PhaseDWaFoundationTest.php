<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\User;
use App\Models\WaMessage;
use App\Models\WaTemplate;
use App\Services\WhatsApp\FakeProvider;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\WaTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseDWaFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AdminUserSeeder::class,
            WaTemplateSeeder::class,
        ]);

        $this->admin = User::where('email', 'admin@kosan.com')->firstOrFail();

        $this->tenant = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '088812345678',
            'wa_number' => '6288812345678',
            'wa_opt_in' => true,
            'password' => bcrypt('password123'),
            'role' => 'penyewa',
            'must_change_password' => false,
        ]);


        FakeProvider::clearSentMessages();
        config(['services.whatsapp.provider' => 'fake']);
    }

    public function test_guest_cannot_access_wa_admin_endpoints(): void
    {
        $this->getJson('/api/admin/wa/connection-status')->assertUnauthorized();
        $this->getJson('/api/admin/wa/messages')->assertUnauthorized();
        $this->postJson('/api/admin/wa/test-send', [])->assertUnauthorized();
    }

    public function test_tenant_cannot_access_wa_admin_endpoints(): void
    {
        $token = $this->tenant->createToken('tenant-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/wa/connection-status')
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/api/admin/wa/test-send', [
                'phone' => '081234567890',
                'message' => 'Halo',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_get_connection_status(): void
    {
        $token = $this->admin->createToken('admin-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/wa/connection-status')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'status',
                'provider',
                'device',
                'sent_last_24h',
                'failed_last_24h',
                'has_high_failure_rate',
                'webhook_url',
            ],
        ]);

        $this->assertSame('fake', $response->json('data.provider'));
    }

    public function test_admin_can_list_templates_and_placeholders(): void
    {
        $token = $this->admin->createToken('admin-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/wa/templates')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'key', 'title', 'body', 'is_active'],
            ],
            'placeholders',
        ]);

        $this->assertGreaterThanOrEqual(10, count($response->json('data')));
        $this->assertArrayHasKey('nama', $response->json('placeholders'));
    }

    public function test_admin_can_send_custom_test_message(): void
    {
        $token = $this->admin->createToken('admin-token')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/admin/wa/test-send', [
                'phone' => '081234567890',
                'message' => 'Pesan uji coba dari Jarvis',
            ])
            ->assertOk();

        $response->assertJson([
            'data' => [
                'phone' => '6281234567890',
                'body' => 'Pesan uji coba dari Jarvis',
            ],
        ]);

        $msgId = $response->json('data.id');
        $this->assertDatabaseHas('wa_messages', [
            'id' => $msgId,
            'phone' => '6281234567890',
            'body' => 'Pesan uji coba dari Jarvis',
            'direction' => 'out',
        ]);

        // Execute the job to verify fake provider integration
        $job = new SendWhatsAppMessageJob($msgId);
        app()->call([$job, 'handle']);

        $message = WaMessage::find($msgId);
        $this->assertSame('sent', $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertNotNull($message->provider_message_id);

        $sentInFake = FakeProvider::getSentMessages();
        $this->assertCount(1, $sentInFake);
        $this->assertSame('6281234567890', $sentInFake[0]['to']);
    }

    public function test_admin_can_send_test_message_with_template(): void
    {
        $token = $this->admin->createToken('admin-token')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/admin/wa/test-send', [
                'phone' => '+62 812-3456-7890',
                'template_key' => 'reminder_due_today',
                'template_params' => [
                    'nama' => 'Budi Santoso',
                    'kamar' => 'VIP-01',
                    'periode' => 'September 2026',
                    'nominal' => 'Rp 1.500.000',
                ],
            ])
            ->assertOk();

        $msgId = $response->json('data.id');
        $message = WaMessage::find($msgId);

        $this->assertStringContainsString('Budi Santoso', $message->body);
        $this->assertStringContainsString('VIP-01', $message->body);
        $this->assertStringContainsString('Rp 1.500.000', $message->body);
        $this->assertSame('reminder_due_today', $message->template_key);
    }

    public function test_message_delivery_failure_is_recorded_and_can_be_resent(): void
    {
        $token = $this->admin->createToken('admin-token')->plainTextToken;

        // Create message in queued state
        $message = WaMessage::create([
            'phone' => '6288812345678',
            'direction' => 'out',
            'status' => 'queued',
            'body' => 'Pesan uji coba kegagalan',
            'provider' => 'fake',
            'attempts' => 0,
        ]);

        // Simulate failure in fake provider
        FakeProvider::simulateFailureNext(true, 'Fonnte device disconnected');

        $job = new SendWhatsAppMessageJob($message->id);
        $job->tries = 1; // Limit tries to 1 for direct failure assertion
        try {
            app()->call([$job, 'handle']);
        } catch (\Exception $e) {
            // expected
        }

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('Fonnte device disconnected', (string) $message->error_message);

        // Admin resends failed message
        $resendResponse = $this->withToken($token)
            ->postJson("/api/admin/wa/messages/{$message->id}/resend")
            ->assertOk();

        $this->assertSame('queued', $resendResponse->json('data.status'));

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertNull($message->error_message);

    }


    public function test_admin_can_list_messages_with_filters(): void
    {
        $token = $this->admin->createToken('admin-token')->plainTextToken;

        WaMessage::create([
            'phone' => '6281234567890',
            'direction' => 'out',
            'status' => 'sent',
            'body' => 'Pesan pertama',
            'provider' => 'fake',
        ]);

        WaMessage::create([
            'phone' => '6289999999999',
            'direction' => 'in',
            'status' => 'received',
            'body' => 'Pesan balasan',
            'provider' => 'fake',
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/admin/wa/messages?direction=out')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('6281234567890', $response->json('data.0.phone'));
    }

    public function test_opted_out_tenant_is_ignored_on_normal_send(): void
    {
        $optedOutTenant = User::create([
            'name' => 'Siti Aminah',
            'email' => 'siti@example.com',
            'phone' => '081299998888',
            'wa_number' => '6281299998888',
            'wa_opt_in' => false,
            'password' => bcrypt('password123'),
            'role' => 'penyewa',
        ]);

        $service = app(\App\Services\WaMessageService::class);
        $message = $service->send('081299998888', 'Pesan notifikasi tagihan');

        $this->assertSame('ignored', $message->status);
        $this->assertStringContainsString('opt-out', (string) $message->error_message);
    }
}
