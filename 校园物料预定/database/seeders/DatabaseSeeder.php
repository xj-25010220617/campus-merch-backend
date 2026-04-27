<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate([
            'email' => 'admin@example.com',
        ], [
            'name' => 'System Admin',
            'password' => Hash::make('Admin123456'),
            'phone' => '13800000000',
            'role' => UserRole::ADMIN,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        User::query()->updateOrCreate([
            'email' => 'test@example.com',
        ], [
            'name' => 'Test User',
            'password' => Hash::make('Password123'),
            'phone' => '13900000000',
            'role' => UserRole::USER,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
