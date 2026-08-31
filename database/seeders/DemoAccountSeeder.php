<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => DemoSeeder::EMAIL],
            [
                'name' => 'Demo Owner',
                'password' => Hash::make('password'),
                'password_set_at' => now(),
                'email_verified_at' => now(),
            ],
        );
    }
}
