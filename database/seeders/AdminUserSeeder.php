<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@kosan.com'],
            [
                'name' => 'Administrator Kosan',
                'phone' => '081234567890',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'must_change_password' => false,
            ]
        );
    }
}
