<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Facility;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class Phase2BookingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Room $availableRoom;
    protected Room $occupiedRoom;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        $this->admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@kosan.com',
            'phone' => '081234567890',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'must_change_password' => false,
        ]);

        $this->availableRoom = Room::create([
            'room_number' => 'A-101',
            'name' => 'Kamar Standard 101',
            'type' => 'standar',
            'base_price' => 1200000,
            'status' => 'kosong',
            'description' => 'Kamar nyaman dan tenang.',
        ]);

        $this->occupiedRoom = Room::create([
            'room_number' => 'B-201',
            'name' => 'Kamar Deluxe 201',
            'type' => 'deluxe',
            'base_price' => 1800000,
            'status' => 'terisi',
            'description' => 'Kamar deluxe terisi.',
        ]);

        $facility = Facility::create([
            'name' => 'AC Hemat Energi',
            'icon_identifier' => 'wind',
            'category' => 'kamar',
        ]);
        $this->availableRoom->facilities()->attach($facility->id);
    }

    public function test_public_can_view_only_available_rooms(): void
    {
        $response = $this->getJson('/api/public/rooms');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.room_number', 'A-101')
            ->assertJsonPath('data.0.is_available', true);
    }

    public function test_public_can_view_room_detail(): void
    {
        $response = $this->getJson('/api/public/rooms/' . $this->availableRoom->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->availableRoom->id)
            ->assertJsonPath('data.facilities.0.name', 'AC Hemat Energi');
    }

    public function test_public_can_submit_booking_with_valid_data_and_ktp(): void
    {
        $ktpFile = UploadedFile::fake()->image('ktp_user.jpg', 600, 400)->size(500);

        $payload = [
            'room_id' => $this->availableRoom->id,
            'name' => 'Budi Santoso',
            'phone' => '081299887766',
            'email' => 'budi@example.com',
            'requested_move_in' => now()->addDays(5)->format('Y-m-d'),
            'ktp_file' => $ktpFile,
        ];

        $response = $this->postJson('/api/public/bookings', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Budi Santoso')
            ->assertJsonPath('data.status', 'menunggu');

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->availableRoom->id,
            'name' => 'Budi Santoso',
            'phone' => '081299887766',
            'status' => 'menunggu',
        ]);

        $booking = Booking::where('phone', '081299887766')->first();
        $this->assertNotNull($booking);
        $this->assertNotEquals('ktp_user.jpg', basename($booking->ktp_file)); // File name harus ter-hash/random UUID
        Storage::disk('local')->assertExists($booking->ktp_file);
    }

    public function test_booking_rejected_if_room_is_not_kosong(): void
    {
        $ktpFile = UploadedFile::fake()->image('ktp.jpg')->size(500);

        $payload = [
            'room_id' => $this->occupiedRoom->id,
            'name' => 'Joko Permana',
            'phone' => '081311223344',
            'requested_move_in' => now()->addDays(2)->format('Y-m-d'),
            'ktp_file' => $ktpFile,
        ];

        $response = $this->postJson('/api/public/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['room_id']);
    }

    public function test_admin_can_list_and_view_booking_with_signed_ktp_url(): void
    {
        $ktpFile = UploadedFile::fake()->image('ktp.png')->size(300);
        $storedPath = Storage::disk('local')->putFileAs('private/ktp', $ktpFile, 'ktp-uuid-test.png');

        $booking = Booking::create([
            'room_id' => $this->availableRoom->id,
            'name' => 'Siti Nurhaliza',
            'phone' => '081544332211',
            'ktp_file' => $storedPath,
            'requested_move_in' => now()->addDays(3),
            'status' => 'menunggu',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/bookings');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $booking->id);

        $detailResponse = $this->actingAs($this->admin)->getJson('/api/admin/bookings/' . $booking->id);
        $detailResponse->assertStatus(200);

        $signedUrl = $detailResponse->json('data.ktp_preview_url');
        $this->assertNotNull($signedUrl);
        $this->assertStringContainsString('signature=', $signedUrl);

        // Akses langsung menggunakan signed URL harus sukses
        $ktpStreamResponse = $this->get($signedUrl);
        $ktpStreamResponse->assertStatus(200);

        // Akses tanpa signature harus ditolak (403/Forbidden)
        $unsignedUrl = "/api/admin/bookings/{$booking->id}/ktp";
        $forbiddenResponse = $this->get($unsignedUrl);
        $forbiddenResponse->assertStatus(403);
    }

    public function test_admin_can_approve_booking_with_pessimistic_lock(): void
    {
        $ktpFile = UploadedFile::fake()->image('ktp.png')->size(300);
        $storedPath = Storage::disk('local')->putFileAs('private/ktp', $ktpFile, 'ktp-uuid-test2.png');

        $booking = Booking::create([
            'room_id' => $this->availableRoom->id,
            'name' => 'Rian Kurniawan',
            'phone' => '081987654321',
            'email' => 'rian@example.com',
            'ktp_file' => $storedPath,
            'requested_move_in' => now()->addDays(4),
            'status' => 'menunggu',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this->actingAs($this->admin)->patchJson("/api/admin/bookings/{$booking->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'disetujui');

        // Status kamar berubah jadi terisi
        $this->assertEquals('terisi', $this->availableRoom->fresh()->status);

        // Tenancy baru terbentuk otomatis
        $this->assertDatabaseHas('tenancies', [
            'room_id' => $this->availableRoom->id,
            'tenant_name' => 'Rian Kurniawan',
            'tenant_phone' => '081987654321',
            'status' => 'aktif',
        ]);

        // User penghuni baru terbentuk
        $this->assertDatabaseHas('users', [
            'phone' => '081987654321',
            'role' => 'penyewa',
        ]);
    }

    public function test_admin_cannot_approve_if_room_already_occupied(): void
    {
        $ktpFile = UploadedFile::fake()->image('ktp.png')->size(300);
        $storedPath = Storage::disk('local')->putFileAs('private/ktp', $ktpFile, 'ktp-uuid-test3.png');

        $booking = Booking::create([
            'room_id' => $this->availableRoom->id,
            'name' => 'Calon Kedua',
            'phone' => '081999888777',
            'ktp_file' => $storedPath,
            'requested_move_in' => now()->addDays(4),
            'status' => 'menunggu',
            'expires_at' => now()->addDays(3),
        ]);

        // Kamar diubah jadi terisi sebelumnya
        $this->availableRoom->update(['status' => 'terisi']);

        $response = $this->actingAs($this->admin)->patchJson("/api/admin/bookings/{$booking->id}/approve");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['room_id']);
    }

    public function test_admin_can_reject_booking_with_reason(): void
    {
        $ktpFile = UploadedFile::fake()->image('ktp.png')->size(300);
        $storedPath = Storage::disk('local')->putFileAs('private/ktp', $ktpFile, 'ktp-uuid-test4.png');

        $booking = Booking::create([
            'room_id' => $this->availableRoom->id,
            'name' => 'Calon Ditolak',
            'phone' => '081777666555',
            'ktp_file' => $storedPath,
            'requested_move_in' => now()->addDays(4),
            'status' => 'menunggu',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this->actingAs($this->admin)->patchJson("/api/admin/bookings/{$booking->id}/reject", [
            'reason' => 'Identitas tidak sesuai atau dokumen KTP buram.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.rejection_reason', 'Identitas tidak sesuai atau dokumen KTP buram.');
    }

    public function test_expire_stale_command_cancels_old_pending_bookings(): void
    {
        $ktpFile = UploadedFile::fake()->image('ktp.png')->size(300);
        $storedPath = Storage::disk('local')->putFileAs('private/ktp', $ktpFile, 'ktp-uuid-test5.png');

        $staleBooking = Booking::create([
            'room_id' => $this->availableRoom->id,
            'name' => 'Calon Expired',
            'phone' => '081222333444',
            'ktp_file' => $storedPath,
            'requested_move_in' => now()->addDays(4),
            'status' => 'menunggu',
            'expires_at' => now()->subDay(), // sudah lewat
        ]);

        $this->artisan('bookings:expire-stale --days=3')
            ->assertSuccessful();

        $this->assertEquals('dibatalkan', $staleBooking->fresh()->status);
    }
}
