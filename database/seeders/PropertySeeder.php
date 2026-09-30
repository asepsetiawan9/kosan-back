<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Seeder;

class PropertySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();

        $properties = [
            [
                'name' => 'Kos Melati Residence',
                'address' => 'Jl. Melati Indah No. 45, Coblong',
                'city' => 'Bandung',
                'province' => 'Jawa Barat',
                'postal_code' => '40132',
                'latitude' => -6.8858340,
                'longitude' => 107.6139120,
                'google_maps_url' => 'https://maps.google.com/?q=-6.8858340,107.6139120',
                'owner_name' => 'Hj. Siti Rohmah',
                'owner_phone' => '081234567890',
                'owner_email' => 'siti.rohmah@example.com',
                'managed_by' => $admin?->id,
            ],
            [
                'name' => 'Graha Harmoni Paviliun',
                'address' => 'Jl. Kaliurang KM 5.2 No. 12, Depok',
                'city' => 'Sleman',
                'province' => 'DI Yogyakarta',
                'postal_code' => '55281',
                'latitude' => -7.7601240,
                'longitude' => 110.3842100,
                'google_maps_url' => 'https://maps.google.com/?q=-7.7601240,110.3842100',
                'owner_name' => 'Bpk. Bambang Sutrisno',
                'owner_phone' => '081987654321',
                'owner_email' => 'bambang.sutrisno@example.com',
                'managed_by' => $admin?->id,
            ],
        ];

        foreach ($properties as $data) {
            Property::firstOrCreate(
                ['name' => $data['name']],
                $data
            );
        }
    }
}
