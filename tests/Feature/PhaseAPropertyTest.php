<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseAPropertyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_guest_cannot_access_property_endpoints(): void
    {
        $response = $this->getJson('/api/admin/properties');
        $response->assertStatus(401);
    }

    public function test_admin_can_list_properties_with_stats(): void
    {
        $admin = User::where('email', 'admin@kosan.com')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/properties');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'address',
                        'city',
                        'province',
                        'postal_code',
                        'google_maps_url',
                        'owner_name',
                        'owner_phone',
                        'owner_email',
                        'total_rooms',
                        'available_rooms',
                        'occupied_rooms',
                    ],
                ],
            ]);

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_admin_can_create_new_property(): void
    {
        $admin = User::where('email', 'admin@kosan.com')->first();

        $payload = [
            'name' => 'Wisma Cendikia Syariah',
            'address' => 'Jl. Gejayan No. 88',
            'city' => 'Sleman',
            'province' => 'DI Yogyakarta',
            'postal_code' => '55283',
            'latitude' => -7.7701230,
            'longitude' => 110.3901230,
            'google_maps_url' => 'https://maps.google.com/?q=-7.7701230,110.3901230',
            'owner_name' => 'H. Ahmad Dahlan',
            'owner_phone' => '081399887766',
            'owner_email' => 'ahmad@cendikia.com',
            'managed_by' => $admin->id,
        ];

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/properties', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Wisma Cendikia Syariah',
                'owner_name' => 'H. Ahmad Dahlan',
            ]);

        $this->assertDatabaseHas('properties', [
            'name' => 'Wisma Cendikia Syariah',
            'city' => 'Sleman',
        ]);
    }

    public function test_admin_can_view_property_detail(): void
    {
        $admin = User::where('email', 'admin@kosan.com')->first();
        $property = Property::where('name', 'Kos Melati Residence')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/properties/{$property->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $property->id,
                'name' => 'Kos Melati Residence',
            ]);
    }

    public function test_admin_can_update_property(): void
    {
        $admin = User::where('email', 'admin@kosan.com')->first();
        $property = Property::where('name', 'Kos Melati Residence')->first();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/properties/{$property->id}", [
            'name' => 'Kos Melati Residence Premium',
            'owner_phone' => '081234567899',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Kos Melati Residence Premium',
                'owner_phone' => '081234567899',
            ]);

        $this->assertDatabaseHas('properties', [
            'id' => $property->id,
            'name' => 'Kos Melati Residence Premium',
        ]);
    }

    public function test_cannot_delete_property_with_existing_rooms(): void
    {
        $admin = User::where('email', 'admin@kosan.com')->first();
        $property = Property::where('name', 'Kos Melati Residence')->first();

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/properties/{$property->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['property']);

        $this->assertDatabaseHas('properties', ['id' => $property->id]);
    }

    public function test_admin_can_delete_property_without_rooms(): void
    {
        $admin = User::where('email', 'admin@kosan.com')->first();

        $emptyProperty = Property::create([
            'name' => 'Properti Kosong',
            'address' => 'Jl. Kosong No. 0',
            'owner_name' => 'Pak Budi',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/properties/{$emptyProperty->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Properti berhasil dihapus.',
            ]);

        $this->assertSoftDeleted('properties', ['id' => $emptyProperty->id]);
    }

    public function test_room_can_be_created_with_property_id_and_filtered(): void
    {
        $admin = User::where('email', 'admin@kosan.com')->first();
        $property = Property::where('name', 'Kos Melati Residence')->first();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/rooms', [
            'property_id' => $property->id,
            'room_number' => '103',
            'name' => 'Kamar Standar 103',
            'type' => 'standar',
            'base_price' => 1300000,
            'description' => 'Kamar lantai 1',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'room_number' => '103',
                'property_id' => $property->id,
            ]);

        // Filter rooms by property_id
        $filterResponse = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/rooms?property_id={$property->id}");
        $filterResponse->assertStatus(200);

        $roomNumbers = collect($filterResponse->json('data'))->pluck('room_number')->all();
        $this->assertContains('101', $roomNumbers);
        $this->assertContains('102', $roomNumbers);
        $this->assertContains('103', $roomNumbers);
        $this->assertNotContains('201', $roomNumbers);
    }
}
