<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\Room;
use App\Models\RoomImage;
use Illuminate\Database\Seeder;

class SampleRoomSeeder extends Seeder
{
    public function run(): void
    {
        $allFacilities = Facility::all();

        $rooms = [
            [
                'room_number' => '101',
                'name' => 'Kamar Standar Asri 101',
                'type' => 'standar',
                'base_price' => 1200000.00,
                'description' => 'Kamar tipe standar di lantai 1 dengan ventilasi udara sejuk dan pencahayaan alami optimal.',
                'status' => 'kosong',
                'images' => [
                    'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=800&q=80',
                    'https://images.unsplash.com/photo-1586023492125-27b2c045efd7?auto=format&fit=crop&w=800&q=80',
                ],
                'facility_categories' => ['umum', 'kamar'],
            ],
            [
                'room_number' => '102',
                'name' => 'Kamar Deluxe Modern 102',
                'type' => 'deluxe',
                'base_price' => 1750000.00,
                'description' => 'Kamar deluxe dilengkapi AC dingin, kasur springbed empuk, meja kerja, dan kamar mandi dalam.',
                'status' => 'kosong',
                'images' => [
                    'https://images.unsplash.com/photo-1595526114035-0d45ed16cfbf?auto=format&fit=crop&w=800&q=80',
                    'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=800&q=80',
                ],
                'facility_categories' => ['umum', 'kamar', 'kamar_mandi'],
            ],
            [
                'room_number' => '201',
                'name' => 'Kamar VIP Eksekutif 201',
                'type' => 'vip',
                'base_price' => 2400000.00,
                'description' => 'Kamar luas dengan jendela balkon pemandangan asri, water heater, smart TV, dan parkir mobil.',
                'status' => 'kosong',
                'images' => [
                    'https://images.unsplash.com/photo-1505691938895-1758d7feb511?auto=format&fit=crop&w=800&q=80',
                    'https://images.unsplash.com/photo-1598928506311-c55ded91a20c?auto=format&fit=crop&w=800&q=80',
                ],
                'facility_categories' => ['umum', 'kamar', 'kamar_mandi'],
            ],
            [
                'room_number' => '202',
                'name' => 'Kamar Paviliun Suite 202',
                'type' => 'paviliun',
                'base_price' => 3200000.00,
                'description' => 'Unit paviliun privat lengkap dengan area santai terpisah, pantry mini, water heater, dan parkir mobil.',
                'status' => 'kosong',
                'images' => [
                    'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=800&q=80',
                ],
                'facility_categories' => ['umum', 'kamar', 'kamar_mandi'],
            ],
        ];

        foreach ($rooms as $roomData) {
            $images = $roomData['images'];
            $categories = $roomData['facility_categories'];
            unset($roomData['images'], $roomData['facility_categories']);

            $room = Room::updateOrCreate(
                ['room_number' => $roomData['room_number']],
                $roomData
            );

            // Relasi fasilitas
            $matchingFacilities = $allFacilities->filter(fn($f) => in_array($f->category, $categories));
            $room->facilities()->sync($matchingFacilities->pluck('id'));

            // Images
            foreach ($images as $index => $imgPath) {
                RoomImage::updateOrCreate(
                    [
                        'room_id' => $room->id,
                        'image_path' => $imgPath,
                    ],
                    [
                        'is_primary' => $index === 0,
                        'order' => $index,
                    ]
                );
            }
        }
    }
}
