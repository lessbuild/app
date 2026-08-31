<?php

namespace Database\Seeders;

use App\Models\SignInEvent;
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

        foreach ([
            [
                'method' => SignInEvent::METHOD_PASSWORD,
                'ip_address' => '192.0.2.11',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/140.0 Safari/537.36',
                'signed_in_at' => now()->subMinutes(30),
            ],
            [
                'method' => 'github',
                'ip_address' => '198.51.100.21',
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1',
                'signed_in_at' => now()->subDays(2),
            ],
            [
                'method' => 'gitlab',
                'ip_address' => '203.0.113.31',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0',
                'signed_in_at' => now()->subDays(10),
            ],
        ] as $signIn) {
            $user->signIns()->updateOrCreate(
                [
                    'method' => $signIn['method'],
                    'ip_address' => $signIn['ip_address'],
                    'user_agent' => $signIn['user_agent'],
                ],
                ['signed_in_at' => $signIn['signed_in_at']],
            );
        }
    }
}
