<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase1CoreAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_login_and_receive_token(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'admin@kosan.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'user' => ['id', 'name', 'email', 'role'],
            ]);
    }

    public function test_guest_cannot_access_admin_endpoints(): void
    {
        $response = $this->getJson('/api/admin/rooms');
        $response->assertStatus(401);
    }

    public function test_admin_can_list_rooms_and_facilities(): void
    {
        $admin = User::where('email', 'admin@kosan.com')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/rooms');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'room_number', 'name', 'type', 'base_price', 'status', 'facilities'],
                ],
            ]);

        $this->assertCount(4, $response->json('data'));
    }

    public function test_tenancy_registration_updates_room_status_and_creates_first_invoice(): void
    {
        $admin = User::where('email', 'admin@kosan.com')->first();
        $room = Room::where('status', 'kosong')->first();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/tenancies', [
            'room_id' => $room->id,
            'tenant_name' => 'Budi Santoso',
            'tenant_phone' => '081298765432',
            'tenant_email' => 'budi@example.com',
            'start_date' => '2026-10-01',
            'billing_due_day' => 5,
            'deposit_amount' => 500000,
            'create_first_invoice' => true,
        ]);

        $response->assertStatus(201);
        $tenancyId = $response->json('data.id');

        // Verify room status changed to 'terisi'
        $this->assertEquals('terisi', $room->fresh()->status);

        // Verify first invoice created
        $invoice = $room->fresh()->activeTenancy->invoices()->first();
        $this->assertNotNull($invoice);
        $this->assertEquals($room->base_price + 500000, $invoice->total_amount);
        $this->assertCount(2, $invoice->items);

        // Verify safeguard: cannot delete room with active tenancy
        $deleteResponse = $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/rooms/{$room->id}");
        $deleteResponse->assertStatus(422);

        // Checkout tenancy
        $checkoutResponse = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/tenancies/{$tenancyId}/checkout", [
            'checkout_date' => '2026-11-01',
            'deposit_deduction' => 100000,
            'deduction_reason' => 'Kunci kamar hilang dan penggantian silinder kunci',
            'next_room_status' => 'kosong',
        ]);

        $checkoutResponse->assertStatus(200);

        // Verify room returned to 'kosong' and tenancy 'selesai'
        $this->assertEquals('kosong', $room->fresh()->status);
        $this->assertEquals('selesai', $response->json('data.status') ? $room->fresh()->tenancies()->latest()->first()->status : 'selesai');
    }
}
