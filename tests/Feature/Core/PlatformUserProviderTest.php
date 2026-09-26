<?php

namespace Tests\Feature\Core;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PlatformUserProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->string('password')->nullable();
            $table->string('status', 24)->default('active');
            $table->rememberToken();
            $table->timestamps();
        });

        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        Schema::connection('core')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_platform_guard_authenticates_an_active_account_by_normalized_email(): void
    {
        DB::connection('core')->table('users')->insert([
            'id' => '01J8AA00000000000000000000',
            'email' => 'Person@Example.test',
            'email_normalized' => 'person@example.test',
            'password' => Hash::make('correct horse battery staple'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authenticated = Auth::guard('platform')->attempt([
            'email' => '  PERSON@EXAMPLE.TEST ',
            'password' => 'correct horse battery staple',
        ]);

        $this->assertTrue($authenticated);
        $this->assertSame('01J8AA00000000000000000000', Auth::guard('platform')->id());
    }

    public function test_platform_guard_rejects_inactive_accounts_and_wrong_passwords(): void
    {
        DB::connection('core')->table('users')->insert([
            [
                'id' => '01J8AA00000000000000000001',
                'email' => 'inactive@example.test',
                'email_normalized' => 'inactive@example.test',
                'password' => Hash::make('correct password'),
                'status' => 'suspended',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => '01J8AA00000000000000000002',
                'email' => 'active@example.test',
                'email_normalized' => 'active@example.test',
                'password' => Hash::make('correct password'),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertFalse(Auth::guard('platform')->attempt([
            'email' => 'inactive@example.test',
            'password' => 'correct password',
        ]));
        $this->assertFalse(Auth::guard('platform')->attempt([
            'email' => 'active@example.test',
            'password' => 'incorrect password',
        ]));
    }

    public function test_platform_guard_rejects_ambiguous_normalized_emails(): void
    {
        DB::connection('core')->table('users')->insert([
            [
                'id' => '01J8AA00000000000000000003',
                'email' => 'person@example.test',
                'email_normalized' => 'person@example.test',
                'password' => Hash::make('same password'),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => '01J8AA00000000000000000004',
                'email' => 'PERSON@example.test',
                'email_normalized' => 'person@example.test',
                'password' => Hash::make('same password'),
                'status' => 'suspended',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertFalse(Auth::guard('platform')->attempt([
            'email' => 'person@example.test',
            'password' => 'same password',
        ]));
    }
}
