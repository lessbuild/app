<?php

namespace Tests\Feature\Core;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReconcileLegacyIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('status', 24)->default('active');
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
        Schema::connection('monitor')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        config(['platform.products.monitor.database' => 'monitor']);
    }

    protected function tearDown(): void
    {
        Schema::connection('monitor')->dropIfExists('users');
        Schema::connection('core')->dropIfExists('legacy_identity_maps');
        Schema::connection('core')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_default_preview_checks_consistency_without_linking_accounts(): void
    {
        $platformUserId = $this->createPlatformUser('owner@example.test');
        $this->createMonitorUser('owner@example.test');
        $this->createHeldMapping();

        $this->artisan('platform:reconcile-legacy-identity', [
            'product' => 'monitor',
            'source_id' => '41',
            'platform_user_id' => $platformUserId,
        ])
            ->expectsOutputToContain('No data was changed.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'monitor',
            'source_id' => '41',
            'status' => 'needs_review',
            'canonical_id' => null,
        ], 'core');
    }

    public function test_apply_requires_independent_review_fields_and_records_them(): void
    {
        $platformUserId = $this->createPlatformUser('owner@example.test');
        $this->createMonitorUser('owner@example.test');
        $this->createHeldMapping();
        DB::connection('core')->table('legacy_identity_maps')->update([
            'metadata' => json_encode([
                'reason_codes' => ['email_matches_existing_core_account'],
                'manual_reconciliation' => [
                    'reviewer' => 'previous-operator',
                    'evidence_reference' => 'CASE-OLD',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->artisan('platform:reconcile-legacy-identity', [
            'product' => 'monitor',
            'source_id' => '41',
            'platform_user_id' => $platformUserId,
            '--apply' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'monitor',
            'source_id' => '41',
            'status' => 'needs_review',
            'canonical_id' => null,
        ], 'core');

        $this->artisan('platform:reconcile-legacy-identity', [
            'product' => 'monitor',
            'source_id' => '41',
            'platform_user_id' => $platformUserId,
            '--reviewer' => 'operations@example.test',
            '--evidence-ref' => 'CASE-1234',
            '--apply' => true,
        ])
            ->expectsOutputToContain('mapping was reconciled')
            ->assertExitCode(0);

        $mapping = DB::connection('core')->table('legacy_identity_maps')->first();
        $this->assertSame('reconciled', $mapping->status);
        $this->assertSame('user', $mapping->canonical_entity);
        $this->assertSame($platformUserId, $mapping->canonical_id);
        $this->assertSame('manual-identity-reconciliation-v1', $mapping->batch_key);
        $metadata = json_decode($mapping->metadata, true);
        $this->assertSame('operations@example.test', $metadata['manual_reconciliation']['reviewer']);
        $this->assertCount(2, $metadata['manual_reconciliation_history']);
        $this->assertSame('previous-operator', $metadata['manual_reconciliation_history'][0]['reviewer']);
        $this->assertSame('operations@example.test', $metadata['manual_reconciliation_history'][1]['reviewer']);
        $this->assertStringContainsString('email agreement alone is not ownership proof', $mapping->reconciliation_notes);
    }

    public function test_unverified_or_different_emails_never_become_a_reconciled_identity(): void
    {
        $platformUserId = $this->createPlatformUser('owner@example.test');
        $this->createMonitorUser('different@example.test');
        $this->createHeldMapping();

        $this->artisan('platform:reconcile-legacy-identity', [
            'product' => 'monitor',
            'source_id' => '41',
            'platform_user_id' => $platformUserId,
            '--reviewer' => 'operations@example.test',
            '--evidence-ref' => 'CASE-1234',
            '--apply' => true,
        ])->assertExitCode(1);

        DB::connection('monitor')->table('users')->where('id', 41)->update(['email' => 'owner@example.test', 'email_verified_at' => null]);
        $this->artisan('platform:reconcile-legacy-identity', [
            'product' => 'monitor',
            'source_id' => '41',
            'platform_user_id' => $platformUserId,
            '--reviewer' => 'operations@example.test',
            '--evidence-ref' => 'CASE-1234',
            '--apply' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'monitor',
            'source_id' => '41',
            'status' => 'needs_review',
            'canonical_id' => null,
        ], 'core');
    }

    public function test_a_platform_user_cannot_be_linked_to_two_source_accounts_for_one_product(): void
    {
        $platformUserId = $this->createPlatformUser('owner@example.test');
        $this->createMonitorUser('owner@example.test');
        $this->createHeldMapping();
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'monitor',
            'source_entity' => 'user',
            'source_id' => '99',
            'canonical_entity' => 'user',
            'canonical_id' => $platformUserId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('platform:reconcile-legacy-identity', [
            'product' => 'monitor',
            'source_id' => '41',
            'platform_user_id' => $platformUserId,
            '--reviewer' => 'operations@example.test',
            '--evidence-ref' => 'CASE-1234',
            '--apply' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'monitor',
            'source_id' => '41',
            'status' => 'needs_review',
            'canonical_id' => null,
        ], 'core');
    }

    private function createPlatformUser(string $email): string
    {
        $id = (string) Str::ulid();
        DB::connection('core')->table('users')->insert([
            'id' => $id,
            'name' => 'Platform User',
            'email' => $email,
            'email_normalized' => mb_strtolower($email),
            'email_verified_at' => now(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createMonitorUser(string $email, bool $verified = true): void
    {
        DB::connection('monitor')->table('users')->insert([
            'id' => 41,
            'name' => 'Monitor User',
            'email' => $email,
            'email_verified_at' => $verified ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createHeldMapping(): void
    {
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'monitor',
            'source_entity' => 'user',
            'source_id' => '41',
            'status' => 'needs_review',
            'reconciliation_notes' => 'email_matches_existing_core_account',
            'metadata' => json_encode(['reason_codes' => ['email_matches_existing_core_account']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
