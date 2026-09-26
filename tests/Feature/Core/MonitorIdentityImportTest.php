<?php

namespace Tests\Feature\Core;

use App\Core\Services\Migration\ImportMonitorAccountsIntoCore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MonitorIdentityImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        $this->createMonitorUsersTable();
    }

    protected function tearDown(): void
    {
        Schema::connection('monitor')->dropIfExists('users');
        Schema::connection('core')->dropIfExists('legacy_identity_maps');
        Schema::connection('core')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_preview_is_read_only_and_reports_eligible_accounts(): void
    {
        $this->addMonitorUser(1, 'Monitor owner', 'owner@example.test', '$2y$monitor-hash');

        $report = app(ImportMonitorAccountsIntoCore::class)->run();

        $this->assertSame(1, $report['accounts_seen']);
        $this->assertSame(1, $report['ready']);
        $this->assertSame(0, $report['imported']);
        $this->assertDatabaseCount('users', 0, 'core');
        $this->assertDatabaseCount('legacy_identity_maps', 0, 'core');
    }

    public function test_apply_preserves_password_hash_and_verification_and_is_idempotent(): void
    {
        $this->addMonitorUser(10, 'Monitor owner', 'owner@example.test', '$2y$monitor-hash');

        $service = app(ImportMonitorAccountsIntoCore::class);
        $firstRun = $service->run(apply: true);
        $coreUser = DB::connection('core')->table('users')->where('email_normalized', 'owner@example.test')->first();

        $this->assertSame(1, $firstRun['imported']);
        $this->assertSame('$2y$monitor-hash', $coreUser->password);
        $this->assertNotNull($coreUser->email_verified_at);
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'monitor',
            'source_entity' => 'user',
            'source_id' => '10',
            'canonical_entity' => 'user',
            'status' => 'reconciled',
        ], 'core');

        $secondRun = $service->run(apply: true);

        $this->assertSame(1, $secondRun['already_mapped']);
        $this->assertSame(0, $secondRun['imported']);
        $this->assertDatabaseCount('users', 1, 'core');
    }

    public function test_reviewed_email_collision_can_be_rechecked_after_the_conflict_is_resolved(): void
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
        $this->addMonitorUser(20, 'Monitor owner', 'OWNER@example.test', '$2y$monitor-hash');

        $service = app(ImportMonitorAccountsIntoCore::class);
        $firstRun = $service->run(apply: true);

        $this->assertSame(1, $firstRun['needs_review']);
        $this->assertSame(0, $firstRun['imported']);
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'monitor',
            'source_entity' => 'user',
            'source_id' => '20',
            'status' => 'needs_review',
            'canonical_id' => null,
        ], 'core');

        DB::connection('core')->table('users')
            ->where('email_normalized', 'owner@example.test')
            ->update([
                'email' => 'existing-owner@example.test',
                'email_normalized' => 'existing-owner@example.test',
            ]);

        $retry = $service->run(apply: true);

        $this->assertSame(1, $retry['imported']);
        $this->assertSame(0, $retry['needs_review']);
        $mapping = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')->where('source_id', '20')->first();
        $this->assertSame('reconciled', $mapping->status);
        $this->assertContains(
            'email_matches_existing_core_account',
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

    private function createMonitorUsersTable(): void
    {
        Schema::connection('monitor')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->timestamps();
        });
    }

    private function addMonitorUser(int $id, string $name, string $email, string $password): void
    {
        DB::connection('monitor')->table('users')->insert([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => $password,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
