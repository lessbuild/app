<?php

namespace Tests\Feature\Core;

use App\Core\Services\Migration\ImportAnalyticsAccountsIntoCore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AnalyticsIdentityImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        $this->createAnalyticsTables();
    }

    protected function tearDown(): void
    {
        Schema::connection('analytics')->dropIfExists('passkeys');
        Schema::connection('analytics')->dropIfExists('users');
        Schema::connection('core')->dropIfExists('legacy_identity_maps');
        Schema::connection('core')->dropIfExists('passkeys');
        Schema::connection('core')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_preview_is_read_only_and_reports_eligible_accounts_and_passkeys(): void
    {
        $this->addAnalyticsUser(1, 'Analytics owner', 'owner@example.test', '$2y$analytics-hash');
        $this->addAnalyticsPasskey(1, 1, 'Laptop', 'credential-one');

        $report = app(ImportAnalyticsAccountsIntoCore::class)->run();

        $this->assertSame(1, $report['accounts_seen']);
        $this->assertSame(1, $report['ready']);
        $this->assertSame(1, $report['passkeys_seen']);
        $this->assertSame(0, $report['passkeys_imported']);
        $this->assertDatabaseCount('users', 0, 'core');
        $this->assertDatabaseCount('passkeys', 0, 'core');
        $this->assertDatabaseCount('legacy_identity_maps', 0, 'core');
    }

    public function test_apply_preserves_account_password_verification_two_factor_and_passkey_data_idempotently(): void
    {
        $legacyKey = str_repeat('a', 32);
        $legacyCipher = new Encrypter($legacyKey, 'AES-256-CBC');
        config([
            'migration.source_app_keys.analytics' => 'base64:'.base64_encode($legacyKey),
            'migration.source_ciphers.analytics' => 'AES-256-CBC',
        ]);
        $this->addAnalyticsUser(
            10,
            'Analytics owner',
            'owner@example.test',
            '$2y$analytics-hash',
            twoFactorSecret: $legacyCipher->encrypt('analytics-secret'),
            recoveryCodes: $legacyCipher->encrypt('["recovery-one","recovery-two"]'),
        );
        $this->addAnalyticsPasskey(8, 10, 'Laptop', 'credential-analytics-10');

        $service = app(ImportAnalyticsAccountsIntoCore::class);
        $firstRun = $service->run(apply: true);
        $coreUser = DB::connection('core')->table('users')->where('email_normalized', 'owner@example.test')->first();
        $corePasskey = DB::connection('core')->table('passkeys')->where('credential_id', 'credential-analytics-10')->first();

        $this->assertSame(1, $firstRun['imported']);
        $this->assertSame(1, $firstRun['passkeys_imported']);
        $this->assertSame(
            $coreUser->id,
            DB::connection('core')->table('legacy_identity_maps')
                ->where('source_product', 'analytics')
                ->where('source_entity', 'user')
                ->value('canonical_id'),
        );
        $this->assertSame('owner@example.test', $coreUser->email_normalized);
        $this->assertSame('$2y$analytics-hash', $coreUser->password);
        $this->assertNotNull($coreUser->email_verified_at);
        $this->assertSame('analytics-secret', Crypt::decrypt($coreUser->two_factor_secret));
        $this->assertSame('["recovery-one","recovery-two"]', Crypt::decrypt($coreUser->two_factor_recovery_codes));
        $this->assertSame($coreUser->id, $corePasskey->user_id);
        $this->assertSame('credential-analytics-10', $corePasskey->credential_id);
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'analytics',
            'source_entity' => 'passkey',
            'source_id' => '8',
            'canonical_entity' => 'passkey',
            'status' => 'reconciled',
        ], 'core');

        $secondRun = $service->run(apply: true);

        $this->assertSame(1, $secondRun['already_mapped']);
        $this->assertSame(0, $secondRun['imported']);
        $this->assertDatabaseCount('users', 1, 'core');
        $this->assertDatabaseCount('passkeys', 1, 'core');
    }

    public function test_existing_core_email_is_held_for_manual_review_without_linking_accounts(): void
    {
        DB::connection('core')->table('users')->insert([
            'id' => '01J8AA00000000000000000000',
            'name' => 'Existing Core account',
            'email' => 'owner@example.test',
            'email_normalized' => 'owner@example.test',
            'password' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->addAnalyticsUser(20, 'Analytics owner', 'OWNER@example.test', '$2y$analytics-hash');

        $report = app(ImportAnalyticsAccountsIntoCore::class)->run(apply: true);

        $this->assertSame(1, $report['needs_review']);
        $this->assertSame(0, $report['imported']);
        $this->assertSame(1, DB::connection('core')->table('users')->count());
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => '20',
            'status' => 'needs_review',
            'canonical_id' => null,
        ], 'core');
    }

    public function test_encrypted_security_data_without_a_valid_source_key_is_held_for_review(): void
    {
        config(['migration.source_app_keys.analytics' => null]);
        $legacyKey = str_repeat('b', 32);
        $legacyCipher = new Encrypter($legacyKey, 'AES-256-CBC');
        $this->addAnalyticsUser(
            30,
            'Protected analytics owner',
            'protected@example.test',
            '$2y$analytics-hash',
            twoFactorSecret: $legacyCipher->encryptString('analytics-secret'),
        );

        $report = app(ImportAnalyticsAccountsIntoCore::class)->run(apply: true);

        $this->assertSame(1, $report['needs_review']);
        $this->assertSame(0, $report['imported']);
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => '30',
            'status' => 'needs_review',
            'reconciliation_notes' => 'two_factor_secret_requires_source_app_key',
        ], 'core');
        $this->assertDatabaseCount('users', 0, 'core');

        config(['migration.source_app_keys.analytics' => 'base64:'.base64_encode($legacyKey)]);
        $retryReport = app(ImportAnalyticsAccountsIntoCore::class)->run(apply: true);

        $this->assertSame(1, $retryReport['imported']);
        $this->assertSame(0, $retryReport['needs_review']);
        $mapping = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'analytics')->where('source_id', '30')->first();
        $this->assertSame('reconciled', $mapping->status);
        $this->assertContains(
            'two_factor_secret_requires_source_app_key',
            json_decode($mapping->metadata, true, 512, JSON_THROW_ON_ERROR)['review_history'][0]['reason_codes'],
        );
    }

    public function test_passkey_collision_holds_the_whole_account_for_review(): void
    {
        DB::connection('core')->table('passkeys')->insert([
            'id' => '01J8BB00000000000000000000',
            'user_id' => '01J8AA00000000000000000000',
            'name' => 'Existing laptop',
            'credential_id' => 'shared-credential',
            'credential' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->addAnalyticsUser(40, 'Analytics owner', 'owner@example.test', '$2y$analytics-hash');
        $this->addAnalyticsPasskey(9, 40, 'Laptop', 'shared-credential');

        $report = app(ImportAnalyticsAccountsIntoCore::class)->run(apply: true);

        $this->assertSame(1, $report['needs_review']);
        $this->assertSame(0, $report['imported']);
        $this->assertDatabaseCount('users', 0, 'core');
        $this->assertDatabaseCount('passkeys', 1, 'core');
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => '40',
            'status' => 'needs_review',
            'reconciliation_notes' => 'passkey_credential_already_linked',
        ], 'core');
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->string('auth_type')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->json('preferences')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('passkeys', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('source_product', 24);
            $table->string('source_entity', 100);
            $table->string('source_id', 191);
            $table->string('canonical_entity', 100)->nullable();
            $table->string('canonical_id', 26)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('batch_key', 100)->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['source_product', 'source_entity', 'source_id']);
        });
    }

    private function createAnalyticsTables(): void
    {
        Schema::connection('analytics')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('analytics')->create('passkeys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    private function addAnalyticsUser(
        int $id,
        string $name,
        string $email,
        string $password,
        ?string $twoFactorSecret = null,
        ?string $recoveryCodes = null,
    ): void {
        DB::connection('analytics')->table('users')->insert([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
            'two_factor_secret' => $twoFactorSecret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addAnalyticsPasskey(int $id, int $userId, string $name, string $credentialId): void
    {
        DB::connection('analytics')->table('passkeys')->insert([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'credential_id' => $credentialId,
            'credential' => json_encode(['public_key' => 'legacy-public-key'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
