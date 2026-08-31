<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoAccountSeeder extends Seeder
{
    public const SESSION_ID = '64656d6f2d62726f777365722d73657373696f6e2d666978747572652d303031';

    public function run(): void
    {
        $user = User::query()->updateOrCreate(
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

        DB::table('sessions')->updateOrInsert(
            ['id' => self::SESSION_ID],
            [
                'user_id' => $user->id,
                'ip_address' => '192.0.2.10',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/140.0 Safari/537.36',
                'payload' => base64_encode(''),
                'last_activity' => now()->timestamp,
            ],
        );
    }
}
