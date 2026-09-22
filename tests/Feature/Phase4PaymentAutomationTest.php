<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase4PaymentAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenantUser;
    protected Room $room;
    protected Tenancy $tenancy;
    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        $this->admin = User::factory()->create([
            'email' => 'admin@kos.local',
            'role' => 'admin',
            'must_change_password' => false,
        ]);

        $this->tenantUser = User::factory()->create([
            'email' => 'budi@gmail.com',
            'phone' => '081234567890',
            'role' => 'penyewa',
            'must_change_password' => false,
        ]);

        $this->room = Room::create([
            'room_number' => '201',
            'name' => 'Kamar Deluxe 201',
            'type' => 'deluxe',
            'base_price' => 1500000.00,
            'status' => 'terisi',
        ]);

        $this->tenancy = Tenancy::create([
            'room_id' => $this->room->id,
            'user_id' => $this->tenantUser->id,
            'tenant_name' => 'Budi Santoso',
            'tenant_phone' => '081234567890',
            'tenant_email' => 'budi@gmail.com',
            'start_date' => now()->subMonth()->toDateString(),
            'billing_due_day' => (int) now()->day,
            'deposit_amount' => 500000.00,
            'deposit_status' => 'ditahan',
            'status' => 'aktif',
        ]);

        $this->invoice = Invoice::create([
            'tenancy_id' => $this->tenancy->id,
            'invoice_number' => 'INV/TEST/201/001',
            'period' => now()->format('Y-m'),
            'total_amount' => 1500000.00,
            'paid_amount' => 0.00,
            'status' => 'belum_bayar',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $this->invoice->id,
            'description' => 'Sewa Pokok Kamar 201',
            'amount' => 1500000.00,
            'item_type' => 'sewa',
        ]);
    }

    public function test_tenant_can_initiate_gateway_payment_and_receive_token(): void
    {
        $token = $this->tenantUser->createToken('tenant', ['role:penyewa'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/tenant/invoices/{$this->invoice->id}/pay", [
                'provider' => 'midtrans',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'payment_id',
                    'order_id',
                    'gross_amount',
                    'snap_token',
                    'redirect_url',
                ],
            ]);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $this->invoice->id,
            'method' => 'gateway',
            'gateway_provider' => 'midtrans',
            'status' => 'pending',
        ]);
    }

    public function test_webhook_processes_valid_signature_and_updates_invoice_to_lunas(): void
    {
        // Create pending payment
        $orderId = 'PAY-TEST-ORD123';
        Payment::create([
            'invoice_id' => $this->invoice->id,
            'amount' => 1500000.00,
            'method' => 'gateway',
            'gateway_provider' => 'midtrans',
            'gateway_transaction_id' => $orderId,
            'status' => 'pending',
        ]);

        $serverKey = (string) config('services.midtrans.server_key');
        $statusCode = '200';
        $grossAmount = '1500000.00';
        $validSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'transaction_status' => 'settlement',
            'signature_key' => $validSignature,
        ];

        $response = $this->postJson('/api/webhook/payment/midtrans', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.invoice_status', 'lunas');

        $this->invoice->refresh();
        $this->assertEquals(1500000.00, (float) $this->invoice->paid_amount);
        $this->assertEquals('lunas', $this->invoice->status);

        Queue::assertPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_webhook_is_idempotent_and_does_not_double_count_already_paid_invoice(): void
    {
        $orderId = 'PAY-IDEMPOTENT-001';
        $payment = Payment::create([
            'invoice_id' => $this->invoice->id,
            'amount' => 1500000.00,
            'method' => 'gateway',
            'gateway_provider' => 'midtrans',
            'gateway_transaction_id' => $orderId,
            'status' => 'success',
            'verified_at' => now(),
        ]);

        // Invoice was already paid
        $this->invoice->update([
            'paid_amount' => 1500000.00,
            'status' => 'lunas',
        ]);

        $serverKey = (string) config('services.midtrans.server_key');
        $validSignature = hash('sha512', $orderId . '200' . '1500000.00' . $serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'settlement',
            'signature_key' => $validSignature,
        ];

        // Call webhook again (retry simulation)
        $response = $this->postJson('/api/webhook/payment/midtrans', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'already_processed');

        $this->invoice->refresh();
        // Paid amount must NOT be doubled
        $this->assertEquals(1500000.00, (float) $this->invoice->paid_amount);
    }

    public function test_tenant_can_submit_manual_payment_with_proof_file(): void
    {
        $token = $this->tenantUser->createToken('tenant', ['role:penyewa'])->plainTextToken;
        $file = UploadedFile::fake()->image('bukti_transfer.jpg', 600, 600);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/tenant/invoices/{$this->invoice->id}/manual-pay", [
                'amount' => 1500000,
                'proof_file' => $file,
                'notes' => 'Transfer via BCA an Budi',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.method', 'manual_transfer')
            ->assertJsonPath('data.status', 'pending');

        $this->invoice->refresh();
        $this->assertEquals('menunggu_verifikasi', $this->invoice->status);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $this->invoice->id,
            'method' => 'manual_transfer',
            'status' => 'pending',
        ]);

        Queue::assertPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_admin_can_verify_and_approve_manual_payment(): void
    {
        $payment = Payment::create([
            'invoice_id' => $this->invoice->id,
            'amount' => 1500000.00,
            'method' => 'manual_transfer',
            'proof_file' => 'private/payment_proofs/sample.jpg',
            'status' => 'pending',
        ]);
        $this->invoice->update(['status' => 'menunggu_verifikasi']);

        $token = $this->admin->createToken('admin', ['role:admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/payments/{$payment->id}/verify", [
                'action' => 'approve',
                'notes' => 'Bukti mutasi rekening BCA valid.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'success');

        $payment->refresh();
        $this->assertEquals('success', $payment->status);
        $this->assertEquals($this->admin->id, $payment->verified_by);

        $this->invoice->refresh();
        $this->assertEquals('lunas', $this->invoice->status);
        $this->assertEquals(1500000.00, (float) $this->invoice->paid_amount);

        Queue::assertPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_admin_can_reject_manual_payment_and_restore_invoice_status(): void
    {
        $payment = Payment::create([
            'invoice_id' => $this->invoice->id,
            'amount' => 1500000.00,
            'method' => 'manual_transfer',
            'proof_file' => 'private/payment_proofs/sample.jpg',
            'status' => 'pending',
        ]);
        $this->invoice->update(['status' => 'menunggu_verifikasi']);

        $token = $this->admin->createToken('admin', ['role:admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/payments/{$payment->id}/verify", [
                'action' => 'reject',
                'notes' => 'Nominal transfer tidak sesuai / bukti buram.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'failed');

        $this->invoice->refresh();
        $this->assertEquals('belum_bayar', $this->invoice->status);
        $this->assertEquals(0.00, (float) $this->invoice->paid_amount);

        Queue::assertPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_partial_payment_updates_invoice_status_to_sebagian_dibayar(): void
    {
        $payment = Payment::create([
            'invoice_id' => $this->invoice->id,
            'amount' => 500000.00, // Partial payment (Total: 1.500.000)
            'method' => 'manual_transfer',
            'status' => 'pending',
        ]);

        $token = $this->admin->createToken('admin', ['role:admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/payments/{$payment->id}/verify", [
                'action' => 'approve',
                'notes' => 'Cicilan pertama disetujui.',
            ]);

        $response->assertStatus(200);

        $this->invoice->refresh();
        $this->assertEquals('sebagian_dibayar', $this->invoice->status);
        $this->assertEquals(500000.00, (float) $this->invoice->paid_amount);
    }

    public function test_command_generate_monthly_invoices_creates_invoices_on_due_day(): void
    {
        // Run command for next month
        $nextMonth = now()->addMonth()->format('Y-m');

        $this->artisan("invoices:generate-monthly --period={$nextMonth} --force")
            ->assertExitCode(0);

        $this->assertDatabaseHas('invoices', [
            'tenancy_id' => $this->tenancy->id,
            'period' => $nextMonth,
            'total_amount' => 1500000.00,
            'status' => 'belum_bayar',
        ]);

        // Running again should not duplicate
        $this->artisan("invoices:generate-monthly --period={$nextMonth} --force")
            ->assertExitCode(0);

        $count = Invoice::where('tenancy_id', $this->tenancy->id)
            ->where('period', $nextMonth)
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_command_send_invoice_reminders_dispatches_notifications_on_h_minus_3(): void
    {
        $hMinus3Date = Carbon::today()->addDays(3)->toDateString();
        $this->invoice->update([
            'due_date' => $hMinus3Date,
            'status' => 'belum_bayar',
        ]);

        $this->artisan('invoices:send-reminders')
            ->assertExitCode(0);

        Queue::assertPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_command_mark_overdue_invoices_flags_unpaid_invoices_past_due(): void
    {
        $pastDate = Carbon::today()->subDays(2)->toDateString();
        $this->invoice->update([
            'due_date' => $pastDate,
            'status' => 'belum_bayar',
        ]);

        $this->artisan('invoices:mark-overdue')
            ->assertExitCode(0);

        $this->invoice->refresh();
        $this->assertEquals('terlambat', $this->invoice->status);
    }
}
