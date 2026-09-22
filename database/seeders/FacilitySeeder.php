<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;

class FacilitySeeder extends Seeder
{
    public function run(): void
    {
        $facilities = [
            ['name' => 'WiFi Cepat 100 Mbps', 'icon_identifier' => 'wifi', 'category' => 'umum'],
            ['name' => 'Air Conditioner (AC)', 'icon_identifier' => 'wind', 'category' => 'kamar'],
            ['name' => 'Kamar Mandi Dalam', 'icon_identifier' => 'bath', 'category' => 'kamar_mandi'],
            ['name' => 'Water Heater (Air Hangat)', 'icon_identifier' => 'flame', 'category' => 'kamar_mandi'],
            ['name' => 'Kasur Springbed Queen Size', 'icon_identifier' => 'bed', 'category' => 'kamar'],
            ['name' => 'Lemari Pakaian 2 Pintu & Cermin', 'icon_identifier' => 'archive', 'category' => 'kamar'],
            ['name' => 'Meja & Kursi Kerja Ergonomis', 'icon_identifier' => 'laptop', 'category' => 'kamar'],
            ['name' => 'Dapur Bersama & Kulkas', 'icon_identifier' => 'utensils', 'category' => 'umum'],
            ['name' => 'Area Parkir Motor Aman', 'icon_identifier' => 'bike', 'category' => 'umum'],
            ['name' => 'Area Parkir Mobil Luas', 'icon_identifier' => 'car', 'category' => 'umum'],
        ];

        foreach ($facilities as $facility) {
            Facility::updateOrCreate(
                ['name' => $facility['name']],
                $facility
            );
        }
    }
}
