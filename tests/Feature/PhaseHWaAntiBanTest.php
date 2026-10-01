<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\WhatsAppProviderInterface;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\User;
use App\Models\WaMessage;
use App\Repositories\Contracts\WaMessageRepositoryInterface;
use App\Services\WaChatbotService;
use App\Services\WaMessageService;
use App\Services\WhatsApp\DTO\IncomingMessage;
use App\Services\WhatsApp\DTO\SendResult;
use App\Services\WhatsApp\WaAntiBanGuard;
use App\Services\WhatsApp\WaMessageHumanizer;
use Carbon\Carbon;
use Database\Seeders\WaTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PhaseHWaAntiBanTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('services.whatsapp.antiban.enabled', true);

        $this->seed(WaTemplateSeeder::class);

        $this->admin = User::factory()->create([
            'email' => 'admin@kosan.com',
            'role' => 'admin',
        ]);

        $this->tenant = User::factory()->create([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '081234567890',
            'wa_number' => '6281234567890',
            'role' => 'penyewa',
            'wa_opt_in' => true,
        ]);
    }

    public function test_wa_antiban_guard_enforces_business_hours(): void
    {
        $guard = app(WaAntiBanGuard::class);

        // Outside business hours (02:00 WIB)
        Carbon::setTestNow(Carbon::parse('2026-10-01 02:00:00', 'Asia/Jakarta'));
        $status = $guard->canSend();
        $this->assertFalse($status['allowed']);
        $this->assertStringContainsString('jam operasional', $status['reason']);
        $this->assertGreaterThan(0, $status['retry_after_seconds']);

        // Inside business hours (10:00 WIB)
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta'));
        $statusDay = $guard->canSend();
        $this->assertTrue($statusDay['allowed']);

        // Outside business hours at night (21:30 WIB)
        Carbon::setTestNow(Carbon::parse('2026-10-01 21:30:00', 'Asia/Jakarta'));
        $statusNight = $guard->canSend();
        $this->assertFalse($statusNight['allowed']);

        Carbon::setTestNow(); // Reset time
    }

    public function test_wa_antiban_guard_enforces_hourly_and_daily_caps(): void
    {
        $guard = app(WaAntiBanGuard::class);
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta'));
        Config::set('services.whatsapp.antiban.hourly_max', 3);
        Config::set('services.whatsapp.antiban.daily_max', 5);

        // Send 3 messages within the hour
        $guard->recordSent();
        $guard->recordSent();
        $guard->recordSent();

        // 4th send in the same hour should be blocked
        $status = $guard->canSend();
        $this->assertFalse($status['allowed']);
        $this->assertStringContainsString('per jam tercapai', $status['reason']);

        // Advance to next hour (11:00 WIB)
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00', 'Asia/Jakarta'));
        $this->assertTrue($guard->canSend()['allowed']);

        // Send 2 more (total 5 today)
        $guard->recordSent();
        $guard->recordSent();

        // Daily cap of 5 should now be hit
        $dailyCheck = $guard->canSend();
        $this->assertFalse($dailyCheck['allowed']);
        $this->assertStringContainsString('harian tercapai', $dailyCheck['reason']);

        Carbon::setTestNow();
    }

    public function test_wa_antiban_guard_circuit_breaker(): void
    {
        $guard = app(WaAntiBanGuard::class);
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta'));
        Config::set('services.whatsapp.antiban.circuit_breaker_threshold', 3);

        // Record 2 failures -> still allowed
        $guard->recordFailure();
        $guard->recordFailure();
        $this->assertTrue($guard->canSend()['allowed']);

        // 3rd failure trips the circuit breaker!
        $guard->recordFailure();
        $status = $guard->canSend();
        $this->assertFalse($status['allowed']);
        $this->assertStringContainsString('Circuit breaker aktif', $status['reason']);

        // Admin resets circuit breaker
        $guard->resetCircuitBreaker();
        $this->assertTrue($guard->canSend()['allowed']);

        Carbon::setTestNow();
    }

    public function test_wa_message_humanizer_adds_diversity(): void
    {
        $humanizer = app(WaMessageHumanizer::class);
        $original = "Halo Budi, tagihan Anda sebesar Rp 1.500.000 telah terbit.";

        $humanized1 = $humanizer->humanize($original);
        $humanized2 = $humanizer->humanize($original);

        // Both humanized versions must contain the original essential content
        $this->assertStringContainsString('tagihan Anda sebesar Rp 1.500.000 telah terbit', $humanized1);
        $this->assertStringContainsString('tagihan Anda sebesar Rp 1.500.000 telah terbit', $humanized2);

        // Invisible jitter ensures payload length is altered without breaking textual display
        $this->assertGreaterThan(strlen($original), strlen($humanized1));
    }

    public function test_send_job_releases_back_to_queue_when_outside_business_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 03:00:00', 'Asia/Jakarta')); // 03:00 AM

        $message = WaMessage::create([
            'direction' => 'out',
            'phone' => '6281234567890',
            'provider' => 'fake',
            'type' => 'text',
            'body' => 'Pengingat sewa kos',
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $mockProvider = $this->createMock(WhatsAppProviderInterface::class);
        $mockProvider->expects($this->never())->method('sendText');

        $job = $this->getMockBuilder(SendWhatsAppMessageJob::class)
            ->setConstructorArgs([$message->id])
            ->onlyMethods(['release'])
            ->getMock();

        $job->expects($this->once())
            ->method('release')
            ->with($this->greaterThan(0));

        $job->handle(
            $mockProvider,
            app(WaMessageRepositoryInterface::class),
            app(WaAntiBanGuard::class),
            app(WaMessageHumanizer::class)
        );

        // Status remains queued, not failed!
        $this->assertEquals('queued', $message->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_send_job_records_anti_ban_stats_on_success(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta'));

        $message = WaMessage::create([
            'direction' => 'out',
            'phone' => '6281234567890',
            'provider' => 'fake',
            'type' => 'text',
            'body' => 'Halo Budi, selamat pagi!',
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $guard = app(WaAntiBanGuard::class);
        $this->assertEquals(0, $guard->getStatus()['hourly_count']);

        $job = new SendWhatsAppMessageJob($message->id);
        $job->handle(
            app(WhatsAppProviderInterface::class),
            app(WaMessageRepositoryInterface::class),
            $guard,
            app(WaMessageHumanizer::class)
        );

        $this->assertEquals('sent', $message->fresh()->status);
        $this->assertEquals(1, $guard->getStatus()['hourly_count']);
        $this->assertEquals(1, $guard->getStatus()['daily_count']);

        Carbon::setTestNow();
    }

    public function test_chatbot_throttles_unknown_sender_replies(): void
    {
        Config::set('services.whatsapp.antiban.unknown_reply_max_per_day', 2);
        $chatbot = app(WaChatbotService::class);
        $unknownPhone = '6289999888777';

        // 1st incoming message from unknown sender: gets replied
        $msg1 = new IncomingMessage(
            providerMessageId: 'prov-1',
            from: $unknownPhone,
            type: 'text',
            text: 'Halo apakah ada kamar kosong?'
        );
        $waMsg1 = WaMessage::create([
            'direction' => 'in',
            'phone' => $unknownPhone,
            'provider' => 'fake',
            'type' => 'text',
            'body' => 'Halo apakah ada kamar kosong?',
            'status' => 'queued',
            'attempts' => 0,
        ]);
        $chatbot->handleIncomingMessage($msg1, $waMsg1);

        // 2nd incoming message: gets replied (max = 2)
        $msg2 = new IncomingMessage(
            providerMessageId: 'prov-2',
            from: $unknownPhone,
            type: 'text',
            text: 'Berapa harganya?'
        );
        $waMsg2 = WaMessage::create([
            'direction' => 'in',
            'phone' => $unknownPhone,
            'provider' => 'fake',
            'type' => 'text',
            'body' => 'Berapa harganya?',
            'status' => 'queued',
            'attempts' => 0,
        ]);
        $chatbot->handleIncomingMessage($msg2, $waMsg2);

        // 3rd incoming message: throttled by anti-ban!
        $msg3 = new IncomingMessage(
            providerMessageId: 'prov-3',
            from: $unknownPhone,
            type: 'text',
            text: 'Ping ping'
        );
        $waMsg3 = WaMessage::create([
            'direction' => 'in',
            'phone' => $unknownPhone,
            'provider' => 'fake',
            'type' => 'text',
            'body' => 'Ping ping',
            'status' => 'queued',
            'attempts' => 0,
        ]);
        $chatbot->handleIncomingMessage($msg3, $waMsg3);

        $this->assertEquals('ignored', $waMsg3->fresh()->status);
        $this->assertStringContainsString('anti-ban', (string) $waMsg3->fresh()->error_message);
    }

    public function test_admin_can_view_antiban_status_and_reset_circuit_breaker(): void
    {
        $guard = app(WaAntiBanGuard::class);
        Config::set('services.whatsapp.antiban.circuit_breaker_threshold', 1);
        $guard->recordFailure(); // Trips circuit breaker

        // 1. Connection status includes anti-ban metrics
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/wa/connection-status');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'antiban' => [
                    'hourly_count',
                    'hourly_max',
                    'daily_count',
                    'daily_max',
                    'is_within_business_hours',
                    'business_hours',
                    'circuit_breaker_open',
                    'consecutive_failures',
                ],
            ],
        ]);
        $this->assertTrue($response->json('data.antiban.circuit_breaker_open'));

        // 2. Admin resets circuit breaker
        $resetResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/wa/antiban/reset-circuit');

        $resetResponse->assertStatus(200);
        $this->assertFalse($resetResponse->json('data.circuit_breaker_open'));
        $this->assertFalse($guard->getStatus()['circuit_breaker_open']);
    }

    public function test_non_admin_cannot_reset_circuit_breaker(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->postJson('/api/admin/wa/antiban/reset-circuit');

        $response->assertStatus(403);
    }
}
