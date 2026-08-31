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
                'github_id' => 'demo-github-identity',
                'gitlab_id' => null,
                'bitbucket_id' => null,
                'auth_type' => 'github',
            ],
        );
    }
}
