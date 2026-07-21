<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@kazakora.com'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('admin123'),
                'role' => User::ROLE_ADMIN,
            ]
        );
    }
}
