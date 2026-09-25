<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlatformSsoBrowserFixtureTest extends TestCase
{
    public function test_seed_isolated_core_database_for_browser_sso(): void
    {
        $database = getenv('BROWSER_SSO_DATABASE');
        $this->assertIsString($database);
        $this->assertNotSame('', $database);
        File::ensureDirectoryExists(dirname($database));
        config(['database.connections.core.database' => $database]);
        DB::purge('core');
        File::put($database, '');

        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('status', 24)->default('active');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::connection('core')->create('platform_auth_sessions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->index();
            $table->string('remember_token_hash', 64)->nullable()->index();
            $table->boolean('remembered')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::connection('core')->create('platform_sso_tickets', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('token_hash', 64)->unique();
            $table->char('auth_session_id', 26)->index();
            $table->char('user_id', 26)->index();
            $table->string('issuer_origin', 255);
            $table->string('audience_origin', 255);
            $table->text('return_url');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26)->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26)->index();
            $table->char('user_id', 26)->index();
            $table->string('role', 32)->default('member');
            $table->string('status', 24)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        DB::connection('core')->table('users')->insert([
            'id' => (string) Str::ulid(),
            'name' => 'Browser SSO Test',
            'email' => 'browser-sso@example.test',
            'email_normalized' => 'browser-sso@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('correct horse battery staple'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('users', [
            'email_normalized' => 'browser-sso@example.test',
            'status' => 'active',
        ], 'core');
    }
}
