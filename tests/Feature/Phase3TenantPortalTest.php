<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Complaint;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase3TenantPortalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenant1;
    protected User $tenant2;
    protected Room $room1;
    protected Room $room2;
    protected Tenancy $tenancy1;
    protected Tenancy $tenancy2;
    protected Invoice $invoice1;
    protected Invoice $invoice2;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();

        // Admin User
        $this->admin = User::create([
            'name' => 'Admin Kos',
            'email' => 'admin@kosan.com',
            'phone' => '081234567890',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'must_change_password' => false,
        ]);

        // Room 1 & 2
        $this->room1 = Room::create([
            'room_number' => 'A-101',
            'name' => 'Kamar Standard 101',
            'type' => 'standar',
            'base_price' => 1200000,
            'status' => 'terisi',
        ]);

        $this->room2 = Room::create([
            'room_number' => 'B-201',
            'name' => 'Kamar Deluxe 201',
            'type' => 'deluxe',
            'base_price' => 1800000,
            'status' => 'terisi',
        ]);

        $facility = Facility::create([
            'name' => 'WiFi Cepat',
            'icon_identifier' => 'wifi',
            'category' => 'kamar',
        ]);
        $this->room1->facilities()->attach($facility->id);

        // Tenant 1 (Baru, must_change_password = true)
        $this->tenant1 = User::create([
            'name' => 'Andi Pratama',
            'email' => 'andi@example.com',
            'phone' => '081211112222',
            'password' => Hash::make('Kosan#2222'),
            'role' => 'penyewa',
            'must_change_password' => true,
        ]);

        // Tenant 2 (Sudah ganti password)
        $this->tenant2 = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '081233334444',
            'password' => Hash::make('Rahasia123!'),
            'role' => 'penyewa',
            'must_change_password' => false,
        ]);

        // Tenancy 1 untuk Tenant 1
        $this->tenancy1 = Tenancy::create([
            'room_id' => $this->room1->id,
            'user_id' => $this->tenant1->id,
            'tenant_name' => $this->tenant1->name,
            'tenant_phone' => $this->tenant1->phone,
            'tenant_email' => $this->tenant1->email,
            'start_date' => now()->subDays(10)->format('Y-m-d'),
            'billing_due_day' => 1,
            'deposit_amount' => 1200000,
            'deposit_status' => 'ditahan',
            'status' => 'aktif',
        ]);

        // Tenancy 2 untuk Tenant 2
        $this->tenancy2 = Tenancy::create([
            'room_id' => $this->room2->id,
            'user_id' => $this->tenant2->id,
            'tenant_name' => $this->tenant2->name,
            'tenant_phone' => $this->tenant2->phone,
            'tenant_email' => $this->tenant2->email,
            'start_date' => now()->subDays(5)->format('Y-m-d'),
            'billing_due_day' => 1,
            'deposit_amount' => 1800000,
            'deposit_status' => 'ditahan',
            'status' => 'aktif',
        ]);

        // Invoice Tenant 1
        $this->invoice1 = Invoice::create([
            'tenancy_id' => $this->tenancy1->id,
            'invoice_number' => 'INV/2026/09/0001',
            'period' => '2026-09',
            'total_amount' => 1200000,
            'paid_amount' => 0,
            'status' => 'belum_bayar',
            'due_date' => now()->addDays(5)->format('Y-m-d'),
        ]);
        InvoiceItem::create([
            'invoice_id' => $this->invoice1->id,
            'description' => 'Sewa Kamar Periode September 2026',
            'amount' => 1200000,
            'item_type' => 'sewa',
        ]);

        // Invoice Tenant 2
        $this->invoice2 = Invoice::create([
            'tenancy_id' => $this->tenancy2->id,
            'invoice_number' => 'INV/2026/09/0002',
            'period' => '2026-09',
            'total_amount' => 1800000,
            'paid_amount' => 1800000,
            'status' => 'lunas',
            'due_date' => now()->addDays(5)->format('Y-m-d'),
        ]);
    }

    public function test_tenant_can_login_with_email_and_receive_token(): void
    {
        $response = $this->postJson('/api/tenant/login', [
            'login' => 'andi@example.com',
            'password' => 'Kosan#2222',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('must_change_password', true)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_tenant_can_login_with_phone_number(): void
    {
        $response = $this->postJson('/api/tenant/login', [
            'login' => '081233334444',
            'password' => 'Rahasia123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('must_change_password', false)
            ->assertJsonPath('user.name', 'Budi Santoso');
    }

    public function test_tenant_with_must_change_password_is_blocked_with_428(): void
    {
        $token = $this->tenant1->createToken('test')->plainTextToken;

        // Mencoba mengakses rute portal selain change-password
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/tenant/profile');

        $response->assertStatus(428)
            ->assertJsonPath('must_change_password', true);
    }

    public function test_tenant_can_change_password_and_clear_flag(): void
    {
        $token = $this->tenant1->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/tenant/change-password', [
                'current_password' => 'Kosan#2222',
                'new_password' => 'PasswordBaru#1234',
                'new_password_confirmation' => 'PasswordBaru#1234',
            ]);

        $response->assertStatus(200);

        $this->tenant1->refresh();
        $this->assertFalse($this->tenant1->must_change_password);
        $this->assertTrue(Hash::check('PasswordBaru#1234', $this->tenant1->password));

        // Sekarang akses ke profile harus sukses 200
        $profileResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/tenant/profile');

        $profileResponse->assertStatus(200);
    }

    public function test_tenant_can_view_profile_and_active_tenancy(): void
    {
        $token = $this->tenant2->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/tenant/profile');

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Budi Santoso')
            ->assertJsonPath('data.has_active_tenancy', true)
            ->assertJsonPath('data.active_tenancy.room.room_number', 'B-201');
    }

    public function test_tenant_can_only_view_own_invoices_and_forbidden_for_others(): void
    {
        $token2 = $this->tenant2->createToken('test')->plainTextToken;

        // Tenant 2 melihat list tagihan sendiri
        $listResponse = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/tenant/invoices');

        $listResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', 'INV/2026/09/0002');

        // Tenant 2 melihat detail tagihan miliknya sendiri
        $detailResponse = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/tenant/invoices/' . $this->invoice2->id);

        $detailResponse->assertStatus(200)
            ->assertJsonPath('data.id', $this->invoice2->id);

        // Tenant 2 mencoba mengakses invoice milik Tenant 1 (harus 403 Forbidden)
        $tamperResponse = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/tenant/invoices/' . $this->invoice1->id);

        $tamperResponse->assertStatus(403);
    }

    public function test_tenant_can_submit_complaint_and_dispatches_whatsapp_to_admin(): void
    {
        $token2 = $this->tenant2->createToken('test')->plainTextToken;
        $photo = UploadedFile::fake()->image('keran_rusak.jpg', 800, 600)->size(1024);

        $response = $this->withHeader('Authorization', "Bearer {$token2}")
            ->postJson('/api/tenant/complaints', [
                'category' => 'fasilitas_rusak',
                'description' => 'Keran wastafel di kamar mandi bocor dan air terus mengalir deras.',
                'photo' => $photo,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.category', 'fasilitas_rusak')
            ->assertJsonPath('data.status', 'baru');

        $this->assertDatabaseHas('complaints', [
            'tenancy_id' => $this->tenancy2->id,
            'category' => 'fasilitas_rusak',
            'status' => 'baru',
        ]);

        Queue::assertPushed(SendWhatsAppNotificationJob::class, function ($job) {
            return $job->phone === $this->admin->phone && str_contains($job->message, 'Tiket Aduan Baru Masuk');
        });
    }

    public function test_tenant_without_active_tenancy_cannot_submit_complaint(): void
    {
        // Ubah tenancy2 menjadi 'selesai' (post-lease)
        $this->tenancy2->update(['status' => 'selesai']);

        $token2 = $this->tenant2->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token2}")
            ->postJson('/api/tenant/complaints', [
                'category' => 'kebersihan',
                'description' => 'Sampah di lorong belum diangkut sejak kemarin.',
            ]);

        // Harus ditolak oleh policy (403 Forbidden)
        $response->assertStatus(403);
    }

    public function test_admin_can_view_and_update_complaint_status_with_resolution_time(): void
    {
        $complaint = Complaint::create([
            'tenancy_id' => $this->tenancy2->id,
            'category' => 'fasilitas_rusak',
            'description' => 'Lampu utama kamar tidur redup dan berkedip.',
            'status' => 'baru',
        ]);

        $adminToken = $this->admin->createToken('admin')->plainTextToken;

        // Admin lihat list aduan
        $listResponse = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/admin/complaints');

        $listResponse->assertStatus(200)
            ->assertJsonPath('meta.total', 1);

        // Admin update status jadi 'diproses'
        $updateResponse = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->patchJson('/api/admin/complaints/' . $complaint->id, [
                'status' => 'diproses',
                'admin_response' => 'Teknisi sedang dalam perjalanan untuk mengganti bohlam LED baru.',
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'diproses')
            ->assertJsonPath('data.admin_response', 'Teknisi sedang dalam perjalanan untuk mengganti bohlam LED baru.');

        // Admin selesaikan tiket aduan
        $finishResponse = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->patchJson('/api/admin/complaints/' . $complaint->id, [
                'status' => 'selesai',
                'admin_response' => 'Bohlam LED sudah diganti baru dan berfungsi normal.',
            ]);

        $finishResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai');

        $complaint->refresh();
        $this->assertEquals('selesai', $complaint->status);
        $this->assertNotNull($complaint->resolved_at);
    }
}
