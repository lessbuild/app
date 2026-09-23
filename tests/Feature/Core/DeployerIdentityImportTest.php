<?php

namespace Tests\Feature\Core;

use App\Core\Services\Identity\ResolvePlatformUser;
use App\Modules\Deployer\Models\User as DeployerUser;
use App\Modules\Deployer\Services\Migration\ImportAccountsIntoCore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DeployerIdentityImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        $this->createDeployerUsersTable();
    }

    protected function tearDown(): void
    {
        Schema::connection('deployer')->dropIfExists('users');
        Schema::connection('core')->dropIfExists('legacy_identity_maps');
        Schema::connection('core')->dropIfExists('user_identities');
        Schema::connection('core')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_preview_is_read_only_and_does_not_merge_email_collisions(): void
    {
        $this->addDeployerUser(1, 'Owner', 'owner@example.test', '$2y$legacy-hash');
        $this->addDeployerUser(2, 'Second account', 'OWNER@example.test', '$2y$second-hash');

        $report = app(ImportAccountsIntoCore::class)->run();

        $this->assertSame(2, $report['accounts_seen']);
        $this->assertSame(0, $report['ready']);
        $this->assertSame(2, $report['needs_review']);
        $this->assertDatabaseCount('users', 0, 'core');
        $this->assertDatabaseCount('legacy_identity_maps', 0, 'core');
    }

    public function test_apply_preserves_credentials_social_identity_and_is_idempotent(): void
    {
        $this->addDeployerUser(
            10,
            'Owner',
            'owner@example.test',
            '$2y$legacy-hash',
            githubId: 'github-user-10',
            twoFactorSecret: 'legacy-encrypted-secret',
            recoveryCodes: '["recovery-one","recovery-two"]',
        );

        $service = app(ImportAccountsIntoCore::class);
        $firstRun = $service->run(apply: true);

        $this->assertSame(1, $firstRun['imported']);
        $this->assertSame(1, $firstRun['accounts_seen']);
        $this->assertDatabaseHas('users', [
            'email_normalized' => 'owner@example.test',
            'password' => '$2y$legacy-hash',
        ], 'core');
        $storedTwoFactorSecret = DB::connection('core')->table('users')
            ->where('email_normalized', 'owner@example.test')
            ->value('two_factor_secret');
        $this->assertSame('legacy-encrypted-secret', Crypt::decrypt($storedTwoFactorSecret));
        $storedRecoveryCodes = DB::connection('core')->table('users')
            ->where('email_normalized', 'owner@example.test')
            ->value('two_factor_recovery_codes');
        $this->assertSame('["recovery-one","recovery-two"]', Crypt::decrypt($storedRecoveryCodes));
        $this->assertDatabaseHas('user_identities', [
            'provider' => 'github',
            'provider_user_id' => 'github-user-10',
        ], 'core');
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => '10',
            'status' => 'reconciled',
        ], 'core');

        $legacyPrincipal = (new DeployerUser)->forceFill(['id' => 10]);
        $platformUser = app(ResolvePlatformUser::class)->resolve($legacyPrincipal, 'deployer');
        $this->assertNotNull($platformUser);
        $this->assertSame('owner@example.test', $platformUser->email);

        $secondRun = $service->run(apply: true);

        $this->assertSame(1, $secondRun['already_mapped']);
        $this->assertSame(0, $secondRun['imported']);
        $this->assertDatabaseCount('users', 1, 'core');
    }

    public function test_existing_core_email_is_recorded_for_review_without_linking_accounts(): void
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
        $this->addDeployerUser(20, 'Legacy owner', 'owner@example.test', '$2y$legacy-hash');

        $report = app(ImportAccountsIntoCore::class)->run(apply: true);

        $this->assertSame(1, $report['needs_review']);
        $this->assertSame(0, $report['imported']);
        $this->assertSame(1, DB::connection('core')->table('users')->count());
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_id' => '20',
            'status' => 'needs_review',
            'canonical_id' => null,
        ], 'core');
    }

    public function test_encrypted_deployer_two_factor_secret_is_reencrypted_with_the_core_key(): void
    {
        $legacyKey = str_repeat('d', 32);
        $legacyCipher = new Encrypter($legacyKey, 'AES-256-CBC');
        config([
            'migration.source_app_keys.deployer' => 'base64:'.base64_encode($legacyKey),
            'migration.source_ciphers.deployer' => 'AES-256-CBC',
        ]);
        $this->addDeployerUser(
            30,
            'Protected owner',
            'protected@example.test',
            '$2y$legacy-hash',
            twoFactorSecret: $legacyCipher->encryptString('legacy-two-factor-secret'),
        );

        $report = app(ImportAccountsIntoCore::class)->run(apply: true);
        $stored = DB::connection('core')->table('users')
            ->where('email_normalized', 'protected@example.test')
            ->value('two_factor_secret');

        $this->assertSame(1, $report['imported']);
        $this->assertSame('legacy-two-factor-secret', Crypt::decrypt($stored));
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => '30',
            'status' => 'reconciled',
        ], 'core');
    }

    public function test_encrypted_two_factor_secret_without_its_source_key_is_held_for_review(): void
    {
        config(['migration.source_app_keys.deployer' => null]);
        $legacyKey = str_repeat('e', 32);
        $legacyCipher = new Encrypter($legacyKey, 'AES-256-CBC');
        $this->addDeployerUser(
            31,
            'Protected owner',
            'protected@example.test',
            '$2y$legacy-hash',
            twoFactorSecret: $legacyCipher->encryptString('legacy-two-factor-secret'),
        );

        $report = app(ImportAccountsIntoCore::class)->run(apply: true);

        $this->assertSame(1, $report['needs_review']);
        $this->assertSame(0, $report['imported']);
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => '31',
            'status' => 'needs_review',
            'reconciliation_notes' => 'two_factor_secret_requires_source_app_key',
        ], 'core');
        $this->assertDatabaseCount('users', 0, 'core');

        config(['migration.source_app_keys.deployer' => 'base64:'.base64_encode($legacyKey)]);
        $retryReport = app(ImportAccountsIntoCore::class)->run(apply: true);

        $this->assertSame(1, $retryReport['imported']);
        $this->assertSame(0, $retryReport['needs_review']);
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => '31',
            'status' => 'reconciled',
        ], 'core');
        $mapping = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')->where('source_id', '31')->first();
        $this->assertContains(
            'two_factor_secret_requires_source_app_key',
            json_decode($mapping->metadata, true, 512, JSON_THROW_ON_ERROR)['review_history'][0]['reason_codes'],
        );
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

        Schema::connection('core')->create('user_identities', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->string('provider', 48);
            $table->string('provider_user_id', 191);
            $table->string('provider_email')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
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

    private function createDeployerUsersTable(): void
    {
        Schema::connection('deployer')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->string('auth_type')->nullable();
            $table->string('github_id')->nullable();
            $table->string('gitlab_id')->nullable();
            $table->string('bitbucket_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->json('preferences')->nullable();
            $table->timestamps();
        });
    }

    private function addDeployerUser(
        int $id,
        string $name,
        string $email,
        string $password,
        ?string $githubId = null,
        ?string $twoFactorSecret = null,
        ?string $recoveryCodes = null,
    ): void {
        DB::connection('deployer')->table('users')->insert([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'github_id' => $githubId,
            'email_verified_at' => now(),
            'two_factor_secret' => $twoFactorSecret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
