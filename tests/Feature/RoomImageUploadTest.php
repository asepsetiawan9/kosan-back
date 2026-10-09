<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'must_change_password' => false,
        ]);

        $this->property = Property::create([
            'name' => 'Wisma Asri',
            'address' => 'Jl. Asri No. 10',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'postal_code' => '40132',
            'owner_name' => 'Ibu Asri',
        ]);
    }

    public function test_admin_can_create_room_with_uploaded_photo(): void
    {
        $file = UploadedFile::fake()->image('kamar_baru.jpg', 800, 600);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/rooms', [
            'property_id' => $this->property->id,
            'room_number' => '101',
            'name' => 'Kamar 101 Standard',
            'type' => 'standar',
            'base_price' => 1200000,
            'description' => 'Kamar nyaman dan asri',
            'image' => $file,
        ]);

        $response->assertCreated();
        $roomId = $response->json('data.id');

        $room = Room::with('images')->findOrFail($roomId);
        $this->assertCount(1, $room->images);
        $this->assertTrue((bool) $room->images->first()->is_primary);
        $this->assertNotNull($response->json('data.primary_image'));
        Storage::disk('public')->assertExists($room->images->first()->image_path);
    }

    public function test_admin_can_update_room_with_uploaded_photo(): void
    {
        $room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '102',
            'name' => 'Kamar 102 Deluxe',
            'type' => 'deluxe',
            'base_price' => 1500000,
            'status' => 'kosong',
        ]);

        $file = UploadedFile::fake()->image('kamar_update.jpg', 800, 600);

        // Test POST to update room with file upload
        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/rooms/{$room->id}", [
            'room_number' => '102',
            'name' => 'Kamar 102 Deluxe Updated',
            'type' => 'deluxe',
            'base_price' => 1600000,
            'status' => 'kosong',
            'photo' => $file,
        ]);

        $response->assertOk();
        $room->refresh();
        $this->assertEquals('Kamar 102 Deluxe Updated', $room->name);
        $this->assertCount(1, $room->images);
        $this->assertTrue((bool) $room->images->first()->is_primary);
        Storage::disk('public')->assertExists($room->images->first()->image_path);
    }

    public function test_admin_can_upload_room_photo_via_dedicated_endpoint(): void
    {
        $room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '103',
            'name' => 'Kamar 103 VIP',
            'type' => 'vip',
            'base_price' => 2000000,
            'status' => 'kosong',
        ]);

        $file = UploadedFile::fake()->image('kamar_vip.jpg', 800, 600);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/rooms/{$room->id}/images", [
            'image' => $file,
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.primary_image'));
        $room->refresh();
        $this->assertCount(1, $room->images);
        Storage::disk('public')->assertExists($room->images->first()->image_path);
    }
}
