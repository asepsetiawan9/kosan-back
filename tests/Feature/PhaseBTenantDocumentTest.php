<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\TenantDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PhaseBTenantDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@kosan.com',
            'phone' => '081234567890',
            'must_change_password' => false,
        ]);

        $this->tenant = User::factory()->create([
            'role' => 'penyewa',
            'email' => 'penyewa@kosan.com',
            'phone' => '089876543210',
            'must_change_password' => false,
        ]);
    }

    public function test_tenant_can_upload_ktp_and_sim_documents(): void
    {
        $token = $this->tenant->createToken('tenant-test')->plainTextToken;

        $fileKtp = UploadedFile::fake()->image('my-ktp.jpg', 800, 600);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/tenant/documents', [
                'document_type' => 'ktp',
                'file' => $fileKtp,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.document_type', 'ktp')
            ->assertJsonPath('data.is_verified', false);

        $this->assertDatabaseHas('tenant_documents', [
            'user_id' => $this->tenant->id,
            'document_type' => 'ktp',
            'is_verified' => false,
        ]);

        // Upload SIM
        $fileSim = UploadedFile::fake()->create('my-sim.pdf', 500, 'application/pdf');

        $responseSim = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/tenant/documents', [
                'document_type' => 'sim',
                'file' => $fileSim,
            ]);

        $responseSim->assertStatus(201)
            ->assertJsonPath('data.document_type', 'sim');

        $this->assertDatabaseHas('tenant_documents', [
            'user_id' => $this->tenant->id,
            'document_type' => 'sim',
        ]);
    }

    public function test_tenant_can_update_nik_with_valid_16_digits(): void
    {
        $token = $this->tenant->createToken('tenant-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/tenant/profile/nik', [
                'nik' => '3201012345670001',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.nik', '3201012345670001');

        $this->assertDatabaseHas('users', [
            'id' => $this->tenant->id,
            'nik' => '3201012345670001',
        ]);
    }

    public function test_update_nik_fails_if_not_16_digits_or_already_taken(): void
    {
        $token = $this->tenant->createToken('tenant-test')->plainTextToken;

        // Kurang dari 16 digit
        $responseShort = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/tenant/profile/nik', [
                'nik' => '12345',
            ]);

        $responseShort->assertStatus(422)
            ->assertJsonValidationErrors(['nik']);

        // Mengandung huruf
        $responseLetters = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/tenant/profile/nik', [
                'nik' => '320101234567000A',
            ]);

        $responseLetters->assertStatus(422)
            ->assertJsonValidationErrors(['nik']);

        // NIK sudah dipakai user lain
        User::factory()->create([
            'nik' => '3201012345670002',
        ]);

        $responseTaken = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/tenant/profile/nik', [
                'nik' => '3201012345670002',
            ]);

        $responseTaken->assertStatus(422)
            ->assertJsonValidationErrors(['nik']);
    }

    public function test_tenant_can_list_own_documents_with_signed_urls(): void
    {
        TenantDocument::create([
            'user_id' => $this->tenant->id,
            'document_type' => 'kk',
            'file_path' => 'private/tenant-documents/test-kk.pdf',
            'original_filename' => 'kartu-keluarga.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120000,
            'is_verified' => false,
        ]);

        $token = $this->tenant->createToken('tenant-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/tenant/documents');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_type', 'kk')
            ->assertJsonPath('data.0.original_filename', 'kartu-keluarga.pdf');

        $this->assertNotEmpty($response->json('data.0.stream_url'));
    }

    public function test_tenant_cannot_delete_verified_document(): void
    {
        $doc = TenantDocument::create([
            'user_id' => $this->tenant->id,
            'document_type' => 'ktp',
            'file_path' => 'private/tenant-documents/test-ktp.jpg',
            'original_filename' => 'ktp.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 50000,
            'is_verified' => true,
            'verified_at' => now(),
            'verified_by' => $this->admin->id,
        ]);

        $token = $this->tenant->createToken('tenant-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/tenant/documents/{$doc->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('tenant_documents', ['id' => $doc->id]);
    }

    public function test_tenant_can_delete_unverified_document(): void
    {
        $fakePath = 'private/tenant-documents/test-unverified.jpg';
        Storage::disk('local')->put($fakePath, 'dummy data');

        $doc = TenantDocument::create([
            'user_id' => $this->tenant->id,
            'document_type' => 'lainnya',
            'file_path' => $fakePath,
            'original_filename' => 'surat.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 20000,
            'is_verified' => false,
        ]);

        $token = $this->tenant->createToken('tenant-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/tenant/documents/{$doc->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('tenant_documents', ['id' => $doc->id]);
        Storage::disk('local')->assertMissing($fakePath);
    }

    public function test_admin_can_view_tenant_documents_and_stream(): void
    {
        $fakePath = "private/tenant-documents/{$this->tenant->id}/ktp.jpg";
        Storage::disk('local')->put($fakePath, 'image content');

        $doc = TenantDocument::create([
            'user_id' => $this->tenant->id,
            'document_type' => 'ktp',
            'file_path' => $fakePath,
            'original_filename' => 'ktp.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'is_verified' => false,
        ]);

        $adminToken = $this->admin->createToken('admin-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson("/api/admin/tenants/{$this->tenant->id}/documents");

        $response->assertStatus(200)
            ->assertJsonPath('tenant.id', $this->tenant->id)
            ->assertJsonCount(1, 'data');

        $streamUrl = $response->json('data.0.stream_url');
        $this->assertNotEmpty($streamUrl);

        // Test stream endpoint directly
        $streamResponse = $this->get($streamUrl);
        $streamResponse->assertStatus(200);
    }

    public function test_admin_can_verify_tenant_document(): void
    {
        $doc = TenantDocument::create([
            'user_id' => $this->tenant->id,
            'document_type' => 'ktp',
            'file_path' => 'private/tenant-documents/test.jpg',
            'original_filename' => 'ktp.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'is_verified' => false,
        ]);

        $adminToken = $this->admin->createToken('admin-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->patchJson("/api/admin/tenants/{$this->tenant->id}/documents/{$doc->id}/verify", [
                'is_verified' => true,
                'notes' => 'KTP telah diverifikasi sesuai data Dukcapil.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.notes', 'KTP telah diverifikasi sesuai data Dukcapil.');

        $this->assertDatabaseHas('tenant_documents', [
            'id' => $doc->id,
            'is_verified' => true,
            'verified_by' => $this->admin->id,
        ]);
    }

    public function test_booking_approval_automatically_syncs_ktp_to_tenant_documents(): void
    {
        $room = Room::create([
            'room_number' => 'B-303',
            'name' => 'Kamar Standard 303',
            'type' => 'standar',
            'base_price' => 1500000,
            'status' => 'kosong',
            'description' => 'Kamar bersih nyaman',
        ]);
        $ktpPath = 'private/ktp/booking-ktp.jpg';
        Storage::disk('local')->put($ktpPath, 'ktp data');

        $booking = Booking::create([
            'room_id' => $room->id,
            'name' => 'Calon Penghuni Baru',
            'phone' => '085511223344',
            'email' => 'calon@example.com',
            'ktp_file' => $ktpPath,
            'requested_move_in' => now()->addDays(2)->format('Y-m-d'),
            'status' => 'menunggu',
            'expires_at' => now()->addDays(3),
        ]);

        $adminToken = $this->admin->createToken('admin-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->patchJson("/api/admin/bookings/{$booking->id}/approve");

        $response->assertStatus(200);

        $createdUser = User::where('phone', '085511223344')->first();
        $this->assertNotNull($createdUser);

        // Pastikan TenantDocument tipe ktp otomatis tercipta & diverifikasi
        $this->assertDatabaseHas('tenant_documents', [
            'user_id' => $createdUser->id,
            'document_type' => 'ktp',
            'file_path' => $ktpPath,
            'is_verified' => true,
        ]);
    }
}
