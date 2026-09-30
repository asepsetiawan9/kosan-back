<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Property;
use App\Models\PropertyMedia;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseCPropertyMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::factory()->create([
            'email' => 'admin@sikos.test',
            'role' => 'admin',
        ]);

        $this->property = Property::create([
            'name' => 'Kosan Putri Melati Exclusive',
            'address' => 'Jl. Kaliurang KM 5, Gang Melati No. 12',
            'city' => 'Sleman',
            'province' => 'DI Yogyakarta',
            'postal_code' => '55281',
            'google_maps_url' => 'https://maps.google.com/?q=-7.7600,110.3700',
            'owner_name' => 'Hj. Siti Rahmawati',
            'owner_phone' => '081234567890',
        ]);
    }

    public function test_guest_cannot_manage_property_media(): void
    {
        $response = $this->getJson("/api/admin/properties/{$this->property->id}/media");
        $response->assertStatus(401);

        $file = UploadedFile::fake()->image('kosan.jpg', 800, 600);
        $postResponse = $this->postJson("/api/admin/properties/{$this->property->id}/media", [
            'file' => $file,
        ]);
        $postResponse->assertStatus(401);
    }

    public function test_admin_can_upload_photo_media_to_property(): void
    {
        $file = UploadedFile::fake()->image('tampak_depan.jpg', 1200, 800);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/properties/{$this->property->id}/media", [
                'file' => $file,
                'media_type' => 'image',
                'title' => 'Tampak Depan Gedung',
                'description' => 'Fasad bangunan modern minimalis 2 lantai',
                'is_featured' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Tampak Depan Gedung')
            ->assertJsonPath('data.media_type', 'image')
            ->assertJsonPath('data.is_featured', true);

        $mediaId = $response->json('data.id');
        $this->assertDatabaseHas('property_media', [
            'id' => $mediaId,
            'property_id' => $this->property->id,
            'media_type' => 'image',
            'title' => 'Tampak Depan Gedung',
            'is_featured' => true,
        ]);

        $savedMedia = PropertyMedia::find($mediaId);
        $this->assertNotNull($savedMedia);
        Storage::disk('public')->assertExists($savedMedia->file_path);
    }

    public function test_admin_can_upload_video_media_and_thumbnail_to_property(): void
    {
        // 1MB fake mp4 video
        $videoFile = UploadedFile::fake()->create('tour_kosan.mp4', 1024, 'video/mp4');
        $thumbFile = UploadedFile::fake()->image('tour_thumb.jpg', 640, 360);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/properties/{$this->property->id}/media", [
                'file' => $videoFile,
                'thumbnail' => $thumbFile,
                'title' => 'Video Virtual Tour Kosan',
                'description' => 'Suasana lorong, dapur bersama, dan area parkir',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.media_type', 'video')
            ->assertJsonPath('data.title', 'Video Virtual Tour Kosan');

        $mediaId = $response->json('data.id');
        $savedMedia = PropertyMedia::find($mediaId);
        $this->assertNotNull($savedMedia);
        $this->assertEquals('video', $savedMedia->media_type);
        Storage::disk('public')->assertExists($savedMedia->file_path);
        Storage::disk('public')->assertExists($savedMedia->thumbnail_path);
    }

    public function test_upload_rejects_file_exceeding_size_limit(): void
    {
        // 6MB image (limit is 5MB)
        $largeImage = UploadedFile::fake()->create('huge.jpg', 6144, 'image/jpeg');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/properties/{$this->property->id}/media", [
                'file' => $largeImage,
                'media_type' => 'image',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_admin_can_toggle_media_featured_status(): void
    {
        $media = PropertyMedia::create([
            'property_id' => $this->property->id,
            'media_type' => 'image',
            'file_path' => 'property-media/test.jpg',
            'title' => 'Foto Kamar Contoh',
            'is_featured' => false,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/properties/{$this->property->id}/media/{$media->id}/featured", [
                'is_featured' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_featured', true);

        $this->assertDatabaseHas('property_media', [
            'id' => $media->id,
            'is_featured' => true,
        ]);
    }

    public function test_admin_can_reorder_property_media(): void
    {
        $media1 = PropertyMedia::create([
            'property_id' => $this->property->id,
            'media_type' => 'image',
            'file_path' => 'property-media/1.jpg',
            'sort_order' => 1,
        ]);

        $media2 = PropertyMedia::create([
            'property_id' => $this->property->id,
            'media_type' => 'image',
            'file_path' => 'property-media/2.jpg',
            'sort_order' => 2,
        ]);

        // Reorder media2 to be first
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/properties/{$this->property->id}/media/reorder", [
                'ordered_ids' => [$media2->id, $media1->id],
            ]);

        $response->assertStatus(200);

        $this->assertEquals(1, $media2->fresh()->sort_order);
        $this->assertEquals(2, $media1->fresh()->sort_order);
    }

    public function test_admin_can_delete_media_and_removes_physical_file(): void
    {
        $filePath = 'property-media/delete_me.jpg';
        Storage::disk('public')->put($filePath, 'dummy content');

        $media = PropertyMedia::create([
            'property_id' => $this->property->id,
            'media_type' => 'image',
            'file_path' => $filePath,
        ]);

        Storage::disk('public')->assertExists($filePath);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/properties/{$this->property->id}/media/{$media->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('property_media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing($filePath);
    }

    public function test_public_can_list_properties_with_media_and_price_range(): void
    {
        // Add 2 rooms to property
        Room::create([
            'property_id' => $this->property->id,
            'room_number' => '101',
            'name' => 'Kamar Standar A1',
            'type' => 'standar',
            'base_price' => 850000,
            'status' => 'kosong',
        ]);

        Room::create([
            'property_id' => $this->property->id,
            'room_number' => '201',
            'name' => 'Kamar VIP B1',
            'type' => 'vip',
            'base_price' => 1500000,
            'status' => 'kosong',
        ]);

        PropertyMedia::create([
            'property_id' => $this->property->id,
            'media_type' => 'image',
            'file_path' => 'property-media/banner.jpg',
            'title' => 'Banner Properti',
            'is_featured' => true,
        ]);

        $response = $this->getJson('/api/public/properties');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Kosan Putri Melati Exclusive')
            ->assertJsonPath('data.0.min_price', 850000)
            ->assertJsonPath('data.0.max_price', 1500000)
            ->assertJsonPath('data.0.available_rooms', 2);
    }

    public function test_public_can_view_property_detail_with_media_and_rooms(): void
    {
        $facility = Facility::create([
            'name' => 'WiFi 100 Mbps',
            'category' => 'umum',
            'icon_identifier' => 'wifi',
        ]);

        $room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '102',
            'name' => 'Kamar Deluxe Exclusive',
            'type' => 'deluxe',
            'base_price' => 1200000,
            'status' => 'kosong',
        ]);
        $room->facilities()->attach($facility->id);

        PropertyMedia::create([
            'property_id' => $this->property->id,
            'media_type' => 'video',
            'file_path' => 'property-media/tour.mp4',
            'title' => 'Video Tur',
            'is_featured' => true,
        ]);

        $response = $this->getJson("/api/public/properties/{$this->property->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->property->id)
            ->assertJsonPath('data.name', 'Kosan Putri Melati Exclusive')
            ->assertJsonPath('data.google_maps_url', 'https://maps.google.com/?q=-7.7600,110.3700')
            ->assertJsonCount(1, 'data.media')
            ->assertJsonCount(1, 'data.rooms')
            ->assertJsonPath('data.rooms.0.room_number', '102');
    }

    public function test_public_room_listing_eager_loads_property_data_with_location(): void
    {
        $room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '103',
            'name' => 'Kamar Standar C3',
            'type' => 'standar',
            'base_price' => 900000,
            'status' => 'kosong',
        ]);

        $response = $this->getJson('/api/public/rooms');

        $response->assertStatus(200);
        $roomData = collect($response->json('data'))->firstWhere('id', $room->id);
        $this->assertNotNull($roomData);
        $this->assertEquals($this->property->id, $roomData['property_id']);
        $this->assertEquals('Kosan Putri Melati Exclusive', $roomData['property']['name']);
        $this->assertEquals('Sleman', $roomData['property']['city']);
        $this->assertEquals('https://maps.google.com/?q=-7.7600,110.3700', $roomData['property']['google_maps_url']);
    }
}
