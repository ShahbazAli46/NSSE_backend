<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Primary Super Admin
        $admin = \App\Models\User::updateOrCreate(
            ['email' => 'shahbaz.ali46@gmail.com'],
            [
                'name' => 'Shahbaz Ali',
                'role' => 'super_admin',
                'password' => null,
                'email_verified_at' => now(),
            ]
        );
        $admin->assignRole('super_admin');
    }
}
