<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaTemplate;
use App\Services\WhatsApp\FakeProvider;
use Carbon\Carbon;
use Database\Seeders\WaTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseFWaChatbotTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenantUser;
    protected Property $property;
    protected Room $room;
    protected Tenancy $tenancy;
    protected Invoice $invoice1;
    protected Invoice $invoice2;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('services.whatsapp.provider', 'fake');
        Config::set('services.whatsapp.webhook_secret', 'test-webhook-secret-xyz');
        FakeProvider::clearSentMessages();

        // Seed default templates
        $this->seed(WaTemplateSeeder::class);

        // Admin User
        $this->admin = User::create([
            'name' => 'Admin Kos',
            'email' => 'admin@kosan.com',
            'phone' => '081299990001',
            'role' => 'admin',
            'password' => bcrypt('password'),
        ]);

        // Tenant User with normalized WhatsApp number: 6281234567890
        $this->tenantUser = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@gmail.com',
            'phone' => '081234567890',
            'wa_number' => '6281234567890',
            'wa_opt_in' => true,
            'role' => 'penyewa',
            'password' => bcrypt('password'),
        ]);

        // Property
        $this->property = Property::create([
            'name' => 'Kos Melati Indah',
            'address' => 'Jl. Mawar No. 10',
            'city' => 'Bandung',
            'owner_name' => 'Pak Haji Mulyana',
        ]);

        // Room
        $this->room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '101',
            'name' => 'Kamar 101 Standar',
            'type' => 'standar',
            'base_price' => 1500000,
            'status' => 'terisi',
        ]);

        // Tenancy
        $this->tenancy = Tenancy::create([
            'room_id' => $this->room->id,
            'user_id' => $this->tenantUser->id,
            'tenant_name' => $this->tenantUser->name,
            'tenant_phone' => $this->tenantUser->phone,
            'tenant_email' => $this->tenantUser->email,
            'start_date' => Carbon::now()->subMonths(2),
            'rent_amount' => 1500000,
            'billing_due_day' => 10,
            'status' => 'aktif',
        ]);

        // Invoice 1 (Unpaid, due earlier)
        $this->invoice1 = Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-2026-001',
            'period' => 'Oktober 2026',
            'total_amount' => 1500000,
            'paid_amount' => 0,
            'due_date' => Carbon::today()->subDays(2),
            'status' => 'terlambat',
        ]);
    }

    public function test_webhook_rejects_unauthorized_token(): void
    {
        $response = $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '6281234567890',
            'message' => 'Halo',
            'token' => 'wrong-token',
        ]);

        $response->assertStatus(401);
    }

    public function test_webhook_idempotency_prevents_duplicate_processing(): void
    {
        $payload = [
            'sender' => '6281234567890',
            'message' => 'menu',
            'id' => 'msg-unique-dedupe-1',
            'token' => 'test-webhook-secret-xyz',
        ];

        // First call
        $firstResponse = $this->postJson('/api/webhooks/whatsapp', $payload);
        $firstResponse->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('wa_messages', [
            'provider_message_id' => 'msg-unique-dedupe-1',
        ]);

        // Second duplicate call
        $secondResponse = $this->postJson('/api/webhooks/whatsapp', $payload);
        $secondResponse->assertStatus(200)
            ->assertJson(['status' => 'already_processed']);

        // Check count remains 1
        $this->assertEquals(1, WaMessage::where('provider_message_id', 'msg-unique-dedupe-1')->count());
    }

    public function test_unknown_sender_gets_polite_bot_unknown_reply_and_no_payment(): void
    {
        $response = $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '089912345678', // Unknown number
            'message' => 'Saya mau bayar kos',
            'id' => 'msg-unknown-1',
            'token' => 'test-webhook-secret-xyz',
        ]);

        $response->assertStatus(200);

        // Check wa_messages record marked ignored
        $this->assertDatabaseHas('wa_messages', [
            'phone' => '6289912345678',
            'status' => 'ignored',
        ]);

        // No payment created
        $this->assertEquals(0, Payment::count());

        // Reply sent with bot_unknown_number
        $sent = FakeProvider::getSentMessages();
        $this->assertNotEmpty($sent);
        $lastSent = end($sent);
        $this->assertEquals('6289912345678', $lastSent['to']);
        $this->assertStringContainsString('belum terdaftar sebagai penghuni', $lastSent['text']);
    }

    public function test_tenant_with_single_unpaid_invoice_sending_proof_creates_pending_payment(): void
    {
        $response = $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'url' => 'https://fake-cdn.com/proof-budi.jpg',
            'filename' => 'transfer_budi.jpg',
            'mime' => 'image/jpeg',
            'id' => 'msg-proof-budi-1',
            'token' => 'test-webhook-secret-xyz',
        ]);

        $response->assertStatus(200);

        // Payment record created
        $payment = Payment::first();
        $this->assertNotNull($payment);
        $this->assertEquals($this->invoice1->id, $payment->invoice_id);
        $this->assertEquals('manual_transfer', $payment->method);
        $this->assertEquals('whatsapp', $payment->source);
        $this->assertEquals(1500000.0, (float) $payment->amount);
        $this->assertEquals('pending', $payment->status);
        $this->assertFalse($payment->is_duplicate_suspect);
        $this->assertNotEmpty($payment->proof_sha256);
        $this->assertNotNull($payment->proof_file);

        // Invoice status updated to menunggu_verifikasi
        $this->invoice1->refresh();
        $this->assertEquals('menunggu_verifikasi', $this->invoice1->status);

        // Confirmation reply sent to tenant
        $sent = FakeProvider::getSentMessages();
        $this->assertNotEmpty($sent);
        $replyFound = false;
        foreach ($sent as $msg) {
            if ($msg['to'] === '6281234567890' && str_contains($msg['text'], 'bukti transfer')) {
                $replyFound = true;
                break;
            }
        }
        $this->assertTrue($replyFound, 'Tenant should receive proof confirmation message');
    }

    public function test_duplicate_proof_image_is_flagged_as_duplicate_suspect(): void
    {
        // Dummy base64 1x1 image sha256 produced by FakeProvider downloadMedia
        $dummyImage = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $expectedSha256 = hash('sha256', $dummyImage);

        // Seed existing payment with that same hash
        Payment::create([
            'invoice_id' => $this->invoice1->id,
            'amount' => 1500000,
            'method' => 'manual_transfer',
            'source' => 'web',
            'proof_file' => 'private/payment_proofs/old_proof.jpg',
            'proof_sha256' => $expectedSha256,
            'status' => 'success',
        ]);

        // Now tenant sends the same image via WhatsApp
        $response = $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'url' => 'https://fake-cdn.com/proof-budi-duplicate.jpg',
            'id' => 'msg-proof-budi-dup',
            'token' => 'test-webhook-secret-xyz',
        ]);

        $response->assertStatus(200);

        // A new payment record was created and flagged suspect
        $newPayment = Payment::where('source', 'whatsapp')->first();
        $this->assertNotNull($newPayment);
        $this->assertTrue($newPayment->is_duplicate_suspect);
        $this->assertEquals('pending', $newPayment->status);
    }

    public function test_tenant_with_multiple_unpaid_invoices_enters_awaiting_invoice_choice_and_selects(): void
    {
        // Add second unpaid invoice for the same tenancy
        $this->invoice2 = Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-2026-002',
            'period' => 'November 2026',
            'total_amount' => 1500000,
            'paid_amount' => 0,
            'due_date' => Carbon::today()->addDays(10),
            'status' => 'belum_bayar',
        ]);

        // 1. Tenant sends image
        $response = $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'url' => 'https://fake-cdn.com/proof-multi.jpg',
            'id' => 'msg-proof-multi-1',
            'token' => 'test-webhook-secret-xyz',
        ]);
        $response->assertStatus(200);

        // Conversation should now be in awaiting_invoice_choice
        $conversation = WaConversation::where('phone', '6281234567890')->first();
        $this->assertNotNull($conversation);
        $this->assertEquals('awaiting_invoice_choice', $conversation->state);
        $this->assertCount(2, $conversation->context['invoice_ids']);

        // Check reply prompt contains options
        $lastSent = FakeProvider::getLastSentMessage();
        $this->assertNotNull($lastSent);
        $this->assertStringContainsString('Oktober 2026', $lastSent['text']);
        $this->assertStringContainsString('November 2026', $lastSent['text']);

        // 2. Tenant replies "2" to choose November 2026
        $choiceResponse = $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'message' => '2',
            'id' => 'msg-reply-choice-2',
            'token' => 'test-webhook-secret-xyz',
        ]);
        $choiceResponse->assertStatus(200);

        // Conversation state should be reset to idle
        $conversation->refresh();
        $this->assertEquals('idle', $conversation->state);

        // Payment should be attached to invoice2
        $payment = Payment::where('invoice_id', $this->invoice2->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals('whatsapp', $payment->source);
        $this->assertEquals('pending', $payment->status);
    }

    public function test_tenant_with_multiple_invoices_invalid_choices_handled(): void
    {
        // Add second invoice
        Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV-2026-002',
            'period' => 'November 2026',
            'total_amount' => 1500000,
            'paid_amount' => 0,
            'due_date' => Carbon::today()->addDays(10),
            'status' => 'belum_bayar',
        ]);

        // Send image to enter choice state
        $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'url' => 'https://fake-cdn.com/proof-multi.jpg',
            'id' => 'msg-proof-test-invalid',
            'token' => 'test-webhook-secret-xyz',
        ]);

        // 1st invalid attempt: out of range number
        $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'message' => '99',
            'id' => 'msg-invalid-1',
            'token' => 'test-webhook-secret-xyz',
        ]);

        $conversation = WaConversation::where('phone', '6281234567890')->first();
        $this->assertEquals('awaiting_invoice_choice', $conversation->state);
        $this->assertEquals(1, $conversation->context['invalid_attempts']);

        // 2nd invalid attempt: random text
        $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'message' => 'halo apa kabar',
            'id' => 'msg-invalid-2',
            'token' => 'test-webhook-secret-xyz',
        ]);

        // Now should be cancelled and reset to idle
        $conversation->refresh();
        $this->assertEquals('idle', $conversation->state);
        $lastSent = FakeProvider::getLastSentMessage();
        $this->assertStringContainsString('dibatalkan karena nomor yang dimasukkan tidak valid', $lastSent['text']);
    }

    public function test_tenant_commands_menu_tagihan_stop_mulai(): void
    {
        // Command 'menu'
        $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'message' => 'menu',
            'id' => 'cmd-menu-1',
            'token' => 'test-webhook-secret-xyz',
        ]);
        $last = FakeProvider::getLastSentMessage();
        $this->assertStringContainsString('layanan otomatis', $last['text']);

        // Command 'tagihan'
        $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'message' => 'tagihan',
            'id' => 'cmd-tagihan-1',
            'token' => 'test-webhook-secret-xyz',
        ]);
        $last = FakeProvider::getLastSentMessage();
        $this->assertStringContainsString('Oktober 2026', $last['text']);
        $this->assertStringContainsString('Rp 1.500.000', $last['text']);

        // Command 'stop'
        $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'message' => 'stop',
            'id' => 'cmd-stop-1',
            'token' => 'test-webhook-secret-xyz',
        ]);
        $this->tenantUser->refresh();
        $this->assertFalse($this->tenantUser->wa_opt_in);

        // Command 'mulai'
        $this->postJson('/api/webhooks/whatsapp', [
            'sender' => '081234567890',
            'message' => 'mulai',
            'id' => 'cmd-mulai-1',
            'token' => 'test-webhook-secret-xyz',
        ]);
        $this->tenantUser->refresh();
        $this->assertTrue($this->tenantUser->wa_opt_in);
    }

    public function test_admin_can_view_wa_payments_and_pending_count(): void
    {
        // Create 1 WA pending payment
        Payment::create([
            'invoice_id' => $this->invoice1->id,
            'amount' => 1500000,
            'method' => 'manual_transfer',
            'source' => 'whatsapp',
            'status' => 'pending',
            'proof_file' => 'private/payment_proofs/sample.jpg',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/wa/payments');
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'amount', 'source', 'status']]]);

        $countResponse = $this->actingAs($this->admin)->getJson('/api/admin/wa/payments/pending-count');
        $countResponse->assertStatus(200)
            ->assertJson(['success' => true, 'count' => 1]);
    }

    public function test_admin_can_approve_wa_payment(): void
    {
        $payment = Payment::create([
            'invoice_id' => $this->invoice1->id,
            'amount' => 1500000,
            'method' => 'manual_transfer',
            'source' => 'whatsapp',
            'status' => 'pending',
            'proof_file' => 'private/payment_proofs/sample.jpg',
        ]);

        $response = $this->actingAs($this->admin)->patchJson("/api/admin/wa/payments/{$payment->id}/verify", [
            'action' => 'approve',
            'notes' => 'Bukti mutasi rekening BCA cocok.',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $payment->refresh();
        $this->assertEquals('success', $payment->status);
        $this->assertEquals($this->admin->id, $payment->verified_by);

        // Invoice should be marked lunas
        $this->invoice1->refresh();
        $this->assertEquals('lunas', $this->invoice1->status);
        $this->assertEquals(1500000.0, (float) $this->invoice1->paid_amount);

        // WhatsApp notification proof_approved dispatched
        $lastSent = FakeProvider::getLastSentMessage();
        $this->assertNotNull($lastSent);
        $this->assertEquals('6281234567890', $lastSent['to']);
        $this->assertStringContainsString('LUNAS', $lastSent['text']);
    }

    public function test_admin_can_reject_wa_payment_with_reason(): void
    {
        $payment = Payment::create([
            'invoice_id' => $this->invoice1->id,
            'amount' => 1500000,
            'method' => 'manual_transfer',
            'source' => 'whatsapp',
            'status' => 'pending',
            'proof_file' => 'private/payment_proofs/sample.jpg',
        ]);

        // Rejection without reason fails validation
        $failResponse = $this->actingAs($this->admin)->patchJson("/api/admin/wa/payments/{$payment->id}/verify", [
            'action' => 'reject',
        ]);
        $failResponse->assertStatus(422);

        // Rejection with valid reason
        $response = $this->actingAs($this->admin)->patchJson("/api/admin/wa/payments/{$payment->id}/verify", [
            'action' => 'reject',
            'reject_reason' => 'Nominal di struk ATM tidak terbaca jelas dan buram.',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $payment->refresh();
        $this->assertEquals('failed', $payment->status);
        $this->assertEquals('Nominal di struk ATM tidak terbaca jelas dan buram.', $payment->reject_reason);

        // Invoice restored to belum_bayar
        $this->invoice1->refresh();
        $this->assertEquals('belum_bayar', $this->invoice1->status);

        // WhatsApp notification proof_rejected dispatched with reason
        $lastSent = FakeProvider::getLastSentMessage();
        $this->assertNotNull($lastSent);
        $this->assertEquals('6281234567890', $lastSent['to']);
        $this->assertStringContainsString('buram', $lastSent['text']);
    }

    public function test_admin_can_record_manual_payment(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/wa/payments/manual', [
            'invoice_id' => $this->invoice1->id,
            'amount' => 1500000,
            'notes' => 'Pembayaran tunai di tempat diterima langsung.',
            'auto_approve' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $this->invoice1->id,
            'source' => 'manual_admin',
            'status' => 'success',
        ]);

        $this->invoice1->refresh();
        $this->assertEquals('lunas', $this->invoice1->status);
    }

    public function test_conversation_ttl_expiration_command(): void
    {
        // Create expired conversation
        WaConversation::create([
            'phone' => '6281234567890',
            'state' => 'awaiting_invoice_choice',
            'context' => ['foo' => 'bar'],
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('wa:expire-conversations')
            ->assertExitCode(0);

        $conversation = WaConversation::where('phone', '6281234567890')->first();
        $this->assertEquals('idle', $conversation->state);
        $this->assertNull($conversation->context);
    }
}
