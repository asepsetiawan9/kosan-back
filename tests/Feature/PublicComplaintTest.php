<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Property;
use App\Models\PublicComplaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicComplaintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_public_user_can_submit_complaint_without_authentication(): void
    {
        Queue::fake();
        Storage::fake('public');

        $property = Property::first();

        $photo1 = UploadedFile::fake()->image('rusak1.jpg', 600, 400);
        $photo2 = UploadedFile::fake()->image('rusak2.png', 600, 400);

        $response = $this->postJson('/api/public/complaints', [
            'reporter_name' => 'Asep Surasep',
            'reporter_phone' => '081234567890',
            'property_id' => $property?->id,
            'room_number' => '102',
            'category' => 'fasilitas_rusak',
            'description' => 'Kran air di wastafel kamar mandi bocor terus dan mengalir ke lantai.',
            'photos' => [$photo1, $photo2],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.reporter_name', 'Asep Surasep')
            ->assertJsonPath('data.category', 'fasilitas_rusak')
            ->assertJsonPath('data.status', 'baru');

        $this->assertDatabaseHas('public_complaints', [
            'reporter_name' => 'Asep Surasep',
            'reporter_phone' => '081234567890',
            'category' => 'fasilitas_rusak',
            'status' => 'baru',
        ]);

        Queue::assertPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_public_complaint_validates_required_fields(): void
    {
        $response = $this->postJson('/api/public/complaints', [
            'reporter_name' => 'A', // too short (<3)
            'reporter_phone' => '123', // too short (<10)
            'category' => 'invalid_cat',
            'description' => 'Pendek', // too short (<15)
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reporter_name', 'reporter_phone', 'category', 'description']);
    }

    public function test_admin_can_list_and_filter_public_complaints(): void
    {
        $admin = User::where('role', 'admin')->first();

        PublicComplaint::create([
            'reporter_name' => 'Budi Santoso',
            'reporter_phone' => '08111222333',
            'category' => 'kebersihan',
            'description' => 'Sampah di lorong lantai 2 menumpuk belum diangkut.',
            'status' => 'baru',
        ]);

        PublicComplaint::create([
            'reporter_name' => 'Citra Dewi',
            'reporter_phone' => '08199887766',
            'category' => 'keamanan',
            'description' => 'Gerbang depan lupa digembok saat malam hari.',
            'status' => 'selesai',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/public-complaints?status=baru');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reporter_name', 'Budi Santoso');
    }

    public function test_admin_can_update_public_complaint_status_and_response(): void
    {
        $admin = User::where('role', 'admin')->first();

        $complaint = PublicComplaint::create([
            'reporter_name' => 'Dedi Setiawan',
            'reporter_phone' => '08122334455',
            'category' => 'air_listrik',
            'description' => 'Lampu lorong depan kamar 201 mati total sejak kemarin sore.',
            'status' => 'baru',
        ]);

        $response = $this->actingAs($admin)
            ->patchJson("/api/admin/public-complaints/{$complaint->id}", [
                'status' => 'selesai',
                'admin_response' => 'Lampu LED lorong sudah diganti baru oleh petugas teknisi.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai')
            ->assertJsonPath('data.admin_response', 'Lampu LED lorong sudah diganti baru oleh petugas teknisi.');

        $this->assertDatabaseHas('public_complaints', [
            'id' => $complaint->id,
            'status' => 'selesai',
            'admin_response' => 'Lampu LED lorong sudah diganti baru oleh petugas teknisi.',
        ]);

        $this->assertNotNull(PublicComplaint::find($complaint->id)->resolved_at);
    }
}
