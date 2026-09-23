<?php

namespace Tests\Feature\Core;

use App\Core\Services\Migration\ImportMonitorWorkspacesAndApplicationsIntoCore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MonitorWorkspaceApplicationImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        $this->createMonitorTables();
    }

    protected function tearDown(): void
    {
        foreach (['workspace_invitations', 'environments', 'applications', 'user_workspace', 'workspaces', 'users'] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        foreach ([
            'legacy_identity_maps', 'project_resources', 'project_products', 'project_memberships',
            'project_environments', 'projects', 'workspace_invitations', 'workspace_product_access',
            'workspace_memberships', 'workspaces', 'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_preview_is_read_only_and_reports_workspace_application_and_environment_eligibility(): void
    {
        $this->addReconciledUser(1, '01J8AA00000000000000000000', 'owner@example.test');
        $this->addMonitorWorkspace(10, 1, 'Acme Monitor', 'acme');
        $this->addMonitorMembership(10, 1, 'owner');
        $this->addMonitorApplication(20, 10, 'API', 'api');
        $this->addMonitorEnvironment(30, 20, 'Production', 'production', 'active');

        $report = app(ImportMonitorWorkspacesAndApplicationsIntoCore::class)->run();

        $this->assertSame(1, $report['workspaces_ready']);
        $this->assertSame(1, $report['applications_ready']);
        $this->assertSame(1, $report['environments_seen']);
        $this->assertSame(0, $report['workspaces_imported']);
        $this->assertSame(0, $report['applications_imported']);
        $this->assertSame(0, $report['environments_imported']);
        $this->assertDatabaseCount('workspaces', 0, 'core');
        $this->assertDatabaseCount('projects', 0, 'core');
        $this->assertDatabaseCount('legacy_identity_maps', 1, 'core');
    }

    public function test_apply_maps_workspace_application_and_environments_and_is_idempotent(): void
    {
        $this->addReconciledUser(1, '01J8AA00000000000000000000', 'owner@example.test');
        $this->addReconciledUser(2, '01J8BB00000000000000000000', 'viewer@example.test');
        $this->addMonitorWorkspace(10, 1, 'Acme Monitor', 'acme');
        $this->addMonitorMembership(10, 1, 'owner');
        $this->addMonitorMembership(10, 2, 'viewer');
        $this->addMonitorInvitation(15, 10, 'invitee@example.test', 'viewer');
        $this->addMonitorApplication(20, 10, 'API', 'api');
        $this->addMonitorEnvironment(30, 20, 'Production', 'production', 'active');
        $this->addMonitorEnvironment(31, 20, 'Preview', 'preview', 'paused');

        $service = app(ImportMonitorWorkspacesAndApplicationsIntoCore::class);
        $firstRun = $service->run(apply: true);
        $workspaceMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')->where('source_entity', 'workspace')->where('source_id', '10')->first();
        $applicationMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')->where('source_entity', 'application')->where('source_id', '20')->first();
        $environmentMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')->where('source_entity', 'environment')->where('source_id', '30')->first();
        $project = DB::connection('core')->table('projects')->where('id', $applicationMap->canonical_id)->first();
        $resource = DB::connection('core')->table('project_resources')
            ->where('product', 'monitor')->where('resource_type', 'application')->where('resource_id', '20')->first();
        $environment = DB::connection('core')->table('project_environments')->where('id', $environmentMap->canonical_id)->first();
        $environmentResource = DB::connection('core')->table('project_resources')
            ->where('product', 'monitor')->where('resource_type', 'environment')->where('resource_id', '30')->first();

        $this->assertSame(1, $firstRun['workspaces_imported']);
        $this->assertSame(1, $firstRun['applications_imported']);
        $this->assertSame(2, $firstRun['environments_imported']);
        $this->assertSame('workspace', $workspaceMap->canonical_entity);
        $this->assertSame('project', $applicationMap->canonical_entity);
        $this->assertSame('project_environment', $environmentMap->canonical_entity);
        $this->assertSame('active', $project->status);
        $this->assertSame('01J8AA00000000000000000000', $workspaceMap->canonical_id
            ? DB::connection('core')->table('workspaces')->where('id', $workspaceMap->canonical_id)->value('owner_user_id')
            : null);
        $this->assertSame('application', $resource->resource_type);
        $this->assertSame('active', $resource->status);
        $this->assertSame('production', $environment->environment_type);
        $this->assertSame('active', $environment->status);
        $this->assertSame('paused', DB::connection('core')->table('project_environments')->where('name', 'Preview')->value('status'));
        $this->assertSame(2, DB::connection('core')->table('workspace_memberships')->count());
        $this->assertSame(2, DB::connection('core')->table('workspace_product_access')->count());
        $this->assertSame(2, DB::connection('core')->table('project_memberships')->count());
        $this->assertSame('monitor', $environmentResource->product);
        $this->assertDatabaseHas('workspace_invitations', [
            'email_normalized' => 'invitee@example.test',
            'status' => 'pending',
            'token_hash' => 'monitor-invitation-hash',
        ], 'core');

        $secondRun = $service->run(apply: true);

        $this->assertSame(1, $secondRun['workspaces_already_mapped']);
        $this->assertSame(1, $secondRun['applications_already_mapped']);
        $this->assertSame(0, $secondRun['applications_imported']);
        $this->assertDatabaseCount('workspaces', 1, 'core');
        $this->assertDatabaseCount('projects', 1, 'core');
        $this->assertDatabaseCount('project_environments', 2, 'core');
    }

    public function test_conflicting_workspace_owner_roles_are_held_and_retried_after_source_correction(): void
    {
        $this->addReconciledUser(1, '01J8AA00000000000000000000', 'owner@example.test');
        $this->addReconciledUser(2, '01J8BB00000000000000000000', 'other@example.test');
        $this->addMonitorWorkspace(50, 1, 'Shared Monitor', 'shared');
        $this->addMonitorMembership(50, 1, 'owner');
        $this->addMonitorMembership(50, 2, 'owner');
        $this->addMonitorApplication(51, 50, 'Worker', 'worker');

        $service = app(ImportMonitorWorkspacesAndApplicationsIntoCore::class);
        $firstRun = $service->run(apply: true);

        $this->assertSame(1, $firstRun['workspaces_blocked']);
        $this->assertSame(1, $firstRun['applications_blocked']);
        $this->assertDatabaseCount('workspaces', 0, 'core');
        $this->assertDatabaseCount('projects', 0, 'core');

        DB::connection('monitor')->table('user_workspace')
            ->where('workspace_id', 50)->where('user_id', 2)->update(['role' => 'member']);

        $retry = $service->run(apply: true);
        $workspaceMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')->where('source_entity', 'workspace')->where('source_id', '50')->first();
        $metadata = json_decode($workspaceMap->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $retry['workspaces_imported']);
        $this->assertSame(1, $retry['applications_imported']);
        $this->assertContains('workspace_owner_role_conflicts_with_owner_id', $metadata['review_history'][0]['reason_codes']);
        $this->assertDatabaseCount('workspaces', 1, 'core');
        $this->assertDatabaseCount('projects', 1, 'core');
    }

    public function test_authoritative_owner_is_added_when_legacy_member_pivot_is_missing(): void
    {
        $this->addReconciledUser(70, '01J8AA00000000000000000000', 'owner@example.test');
        $this->addMonitorWorkspace(70, 70, 'Owner workspace', 'owner-workspace');

        app(ImportMonitorWorkspacesAndApplicationsIntoCore::class)->run(apply: true);

        $workspaceId = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')
            ->where('source_entity', 'workspace')
            ->where('source_id', '70')
            ->value('canonical_id');

        $this->assertSame('owner', DB::connection('core')->table('workspace_memberships')
            ->where('workspace_id', $workspaceId)->value('role'));
        $this->assertSame(1, DB::connection('core')->table('workspace_product_access')
            ->where('product', 'monitor')->count());
    }

    public function test_deleted_application_and_environment_are_preserved_as_archived_records(): void
    {
        $this->addReconciledUser(80, '01J8AA00000000000000000000', 'owner@example.test');
        $this->addMonitorWorkspace(80, 80, 'Archived Monitor', 'archived');
        $this->addMonitorMembership(80, 80, 'owner');
        $this->addMonitorApplication(81, 80, 'Old Worker', 'old-worker');
        $this->addMonitorEnvironment(82, 81, 'Production', 'production', 'active');
        DB::connection('monitor')->table('applications')->where('id', 81)->update(['deleted_at' => now()]);
        DB::connection('monitor')->table('environments')->where('id', 82)->update(['deleted_at' => now()]);

        app(ImportMonitorWorkspacesAndApplicationsIntoCore::class)->run(apply: true);

        $applicationMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')->where('source_entity', 'application')->where('source_id', '81')->first();
        $project = DB::connection('core')->table('projects')->where('id', $applicationMap->canonical_id)->first();
        $product = DB::connection('core')->table('project_products')->where('project_id', $project->id)->first();
        $environment = DB::connection('core')->table('project_environments')->where('project_id', $project->id)->first();

        $this->assertSame('archived', $project->status);
        $this->assertSame('inactive', $product->status);
        $this->assertSame('archived', $environment->status);
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
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26);
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 32);
            $table->string('status', 24);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->char('invited_by_user_id', 26)->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 32);
            $table->string('status', 24);
            $table->char('granted_by_user_id', 26)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['membership_id', 'product']);
        });
        Schema::connection('core')->create('workspace_invitations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('invited_by_user_id', 26)->nullable();
            $table->string('email');
            $table->string('email_normalized');
            $table->string('role', 32);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 24);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('created_by_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug', 120);
            $table->string('status', 24)->default('active');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'slug']);
        });
        Schema::connection('core')->create('project_environments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('created_by_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug', 120);
            $table->string('environment_type', 32)->default('custom');
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'slug']);
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 32);
            $table->string('status', 24);
            $table->char('granted_by_user_id', 26)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
        Schema::connection('core')->create('project_products', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->string('product', 24);
            $table->string('status', 24);
            $table->char('requested_by_user_id', 26)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'product']);
        });
        Schema::connection('core')->create('project_resources', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('environment_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('resource_type', 100);
            $table->string('resource_id', 191);
            $table->string('resource_public_id', 191)->nullable();
            $table->string('name')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('mapped_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['product', 'resource_type', 'resource_id']);
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

    private function createMonitorTables(): void
    {
        Schema::connection('monitor')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug');
            $table->string('plan')->default('free');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('user_workspace', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 16);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });
        Schema::connection('monitor')->create('workspace_invitations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('email');
            $table->string('role', 16);
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('framework', 80);
            $table->string('framework_version', 40)->nullable();
            $table->string('accent', 24)->default('violet');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->string('name');
            $table->string('slug');
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('event_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function addReconciledUser(int $sourceId, string $canonicalId, string $email): void
    {
        DB::connection('core')->table('users')->insert([
            'id' => $canonicalId,
            'name' => 'Mapped user '.$sourceId,
            'email' => $email,
            'email_normalized' => $email,
            'password' => '$2y$mapped-hash',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'monitor',
            'source_entity' => 'user',
            'source_id' => (string) $sourceId,
            'canonical_entity' => 'user',
            'canonical_id' => $canonicalId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('users')->insert([
            'id' => $sourceId,
            'name' => 'Mapped user '.$sourceId,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addMonitorWorkspace(int $id, int $ownerId, string $name, string $slug): void
    {
        DB::connection('monitor')->table('workspaces')->insert([
            'id' => $id,
            'owner_id' => $ownerId,
            'name' => $name,
            'slug' => $slug,
            'plan' => 'pro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addMonitorMembership(int $workspaceId, int $userId, string $role): void
    {
        DB::connection('monitor')->table('user_workspace')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addMonitorInvitation(int $id, int $workspaceId, string $email, string $role): void
    {
        DB::connection('monitor')->table('workspace_invitations')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'email' => $email,
            'role' => $role,
            'token_hash' => 'monitor-invitation-hash',
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addMonitorApplication(int $id, int $workspaceId, string $name, string $slug): void
    {
        DB::connection('monitor')->table('applications')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'name' => $name,
            'slug' => $slug,
            'framework' => 'Laravel',
            'framework_version' => '13.x',
            'accent' => 'emerald',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addMonitorEnvironment(int $id, int $applicationId, string $name, string $slug, string $status): void
    {
        DB::connection('monitor')->table('environments')->insert([
            'id' => $id,
            'application_id' => $applicationId,
            'name' => $name,
            'slug' => $slug,
            'status' => $status,
            'event_count' => 19,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
