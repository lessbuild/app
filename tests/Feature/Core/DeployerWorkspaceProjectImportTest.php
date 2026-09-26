<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Services\Projects\CreateCanonicalProject;
use App\Modules\Deployer\Services\Migration\ImportWorkspacesAndProjectsIntoCore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeployerWorkspaceProjectImportTest extends TestCase
{
    private const CORE_TABLES = [
        'project_resources',
        'project_environments',
        'project_products',
        'project_memberships',
        'projects',
        'workspace_invitations',
        'workspace_product_access',
        'workspace_memberships',
        'workspaces',
        'legacy_identity_maps',
        'users',
    ];

    private const DEPLOYER_TABLES = [
        'environments',
        'projects',
        'organization_invitations',
        'organization_user',
        'organizations',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        $this->createDeployerTables();
    }

    protected function tearDown(): void
    {
        foreach (self::DEPLOYER_TABLES as $table) {
            Schema::connection('deployer')->dropIfExists($table);
        }

        foreach (self::CORE_TABLES as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_preview_is_read_only_and_reports_unmapped_members(): void
    {
        $this->addCanonicalUser(100, 'owner@example.test');
        $this->addReconciledUserMap(1, 100);
        $this->addOrganization(10, 1);
        $this->addOrganizationMember(10, 1, 'owner');
        $this->addOrganizationMember(10, 2, 'member');
        $this->addProject(20, 10, 1);
        $this->addEnvironment(30, 20);

        $report = app(ImportWorkspacesAndProjectsIntoCore::class)->run();

        $this->assertSame(1, $report['workspaces_ready']);
        $this->assertSame(1, $report['projects_ready']);
        $this->assertSame(1, $report['unmapped_members']);
        $this->assertSame(0, $report['workspaces_imported']);
        $this->assertSame(0, $report['projects_imported']);
        $this->assertSame(0, $report['environments_imported']);
        $this->assertDatabaseCount('workspaces', 0, 'core');
        $this->assertDatabaseCount('projects', 0, 'core');
        $this->assertDatabaseCount('legacy_identity_maps', 1, 'core');
    }

    public function test_apply_preserves_workspace_roles_and_project_environment_mappings_idempotently(): void
    {
        $this->addCanonicalUser(100, 'owner@example.test');
        $this->addCanonicalUser(101, 'developer@example.test');
        $this->addReconciledUserMap(1, 100);
        $this->addReconciledUserMap(2, 101);
        $this->addOrganization(10, 1, 'Less Build', 'less-build', '2020-01-02 03:04:05');
        $this->addOrganizationMember(10, 1, 'owner');
        $this->addOrganizationMember(10, 2, 'developer');
        $this->addProject(20, 10, 2, 'Platform', 'platform', '2021-02-03 04:05:06');
        $this->addEnvironment(30, 20, 'Production', 'production', 'production', true);
        $this->addInvitation(40, 10, 2, 'invitee@example.test', '2030-01-01 00:00:00');

        $importer = app(ImportWorkspacesAndProjectsIntoCore::class);
        $firstRun = $importer->run(apply: true);

        $this->assertSame(1, $firstRun['workspaces_imported']);
        $this->assertSame(1, $firstRun['projects_imported']);
        $this->assertSame(1, $firstRun['environments_imported']);
        $this->assertSame(1, $firstRun['invitations_seen']);
        $this->assertSame(1, $firstRun['invitations_imported']);
        $this->assertSame(0, $firstRun['unmapped_members']);
        $this->assertDatabaseHas('workspaces', [
            'name' => 'Less Build',
            'slug' => 'less-build-deployer-10',
            'owner_user_id' => $this->canonicalUserId(100),
            'created_at' => '2020-01-02 03:04:05',
        ], 'core');
        $this->assertDatabaseHas('workspace_memberships', [
            'user_id' => $this->canonicalUserId(101),
            'role' => 'developer',
            'status' => 'active',
        ], 'core');
        $this->assertDatabaseHas('workspace_product_access', [
            'product' => 'deployer',
            'role' => 'developer',
            'status' => 'active',
        ], 'core');
        $this->assertDatabaseHas('projects', [
            'name' => 'Platform',
            'created_by_user_id' => $this->canonicalUserId(101),
            'created_at' => '2021-02-03 04:05:06',
        ], 'core');
        $this->assertDatabaseHas('project_resources', [
            'product' => 'deployer',
            'resource_type' => 'environment',
            'resource_id' => '30',
        ], 'core');
        $this->assertDatabaseHas('project_environments', [
            'name' => 'Production',
            'environment_type' => 'production',
        ], 'core');
        $this->assertDatabaseHas('workspace_invitations', [
            'email_normalized' => 'invitee@example.test',
            'role' => 'developer',
            'token_hash' => 'legacy-invitation-hash',
            'status' => 'pending',
        ], 'core');
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_entity' => 'organization',
            'source_id' => '10',
            'canonical_entity' => 'workspace',
            'status' => 'reconciled',
        ], 'core');

        $secondRun = $importer->run(apply: true);

        $this->assertSame(1, $secondRun['workspaces_already_mapped']);
        $this->assertSame(1, $secondRun['projects_already_mapped']);
        $this->assertSame(0, $secondRun['workspaces_imported']);
        $this->assertSame(0, $secondRun['projects_imported']);
        $this->assertSame(0, $secondRun['environments_imported']);
        $this->assertSame(0, $secondRun['invitations_imported']);
        $this->assertDatabaseCount('workspaces', 1, 'core');
        $this->assertDatabaseCount('projects', 1, 'core');
        $this->assertDatabaseCount('project_environments', 1, 'core');
    }

    public function test_workspace_and_its_projects_are_blocked_when_legacy_owner_needs_review(): void
    {
        $this->addOrganization(10, 1);
        $this->addProject(20, 10, 1);

        $report = app(ImportWorkspacesAndProjectsIntoCore::class)->run(apply: true);

        $this->assertSame(1, $report['workspaces_blocked']);
        $this->assertSame(1, $report['projects_blocked']);
        $this->assertDatabaseCount('workspaces', 0, 'core');
        $this->assertDatabaseCount('projects', 0, 'core');
    }

    public function test_new_project_is_shared_with_active_workspace_members_without_activating_products(): void
    {
        $this->addCanonicalUser(100, 'owner@example.test');
        $this->addCanonicalUser(101, 'developer@example.test');
        $ownerId = $this->canonicalUserId(100);
        $developerId = $this->canonicalUserId(101);
        $workspace = Workspace::query()->create([
            'owner_user_id' => $ownerId,
            'name' => 'New Workspace',
            'slug' => 'new-workspace',
            'status' => 'active',
        ]);

        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $ownerId,
            'role' => 'owner',
            'status' => 'active',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $developerId,
            'role' => 'developer',
            'status' => 'active',
        ]);

        $creator = PlatformUser::query()->findOrFail($ownerId);
        $create = app(CreateCanonicalProject::class);
        $first = $create->handle($workspace, $creator, 'Storefront');
        $second = $create->handle($workspace, $creator, 'Storefront');

        $this->assertSame('storefront', $first->slug);
        $this->assertSame('storefront-2', $second->slug);
        $this->assertDatabaseCount('project_memberships', 4, 'core');
        $this->assertDatabaseHas('project_memberships', [
            'project_id' => $first->getKey(),
            'user_id' => $developerId,
            'role' => 'developer',
            'status' => 'active',
        ], 'core');
        $this->assertDatabaseCount('project_products', 0, 'core');
    }

    public function test_project_creation_rejects_non_manager_workspace_members(): void
    {
        $this->addCanonicalUser(100, 'developer@example.test');
        $this->addCanonicalUser(101, 'owner@example.test');
        $developerId = $this->canonicalUserId(100);
        $ownerId = $this->canonicalUserId(101);
        $workspace = Workspace::query()->create([
            'owner_user_id' => $ownerId,
            'name' => 'Workspace',
            'slug' => 'workspace',
            'status' => 'active',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $ownerId,
            'role' => 'owner',
            'status' => 'active',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $developerId,
            'role' => 'developer',
            'status' => 'active',
        ]);
        $developer = PlatformUser::query()->findOrFail($developerId);

        try {
            app(CreateCanonicalProject::class)->handle($workspace, $developer, 'Unauthorized project');
            $this->fail('A developer membership must not create workspace projects.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('projects', 0, 'core');
        }
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('email')->nullable();
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
            $table->string('status', 24);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'slug']);
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

        Schema::connection('core')->create('project_environments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('created_by_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug', 120);
            $table->string('environment_type', 32);
            $table->string('status', 24);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'slug']);
        });

        Schema::connection('core')->create('project_products', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->string('product', 24);
            $table->string('status', 24);
            $table->char('requested_by_user_id', 26)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
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
            $table->string('status', 24);
            $table->timestamp('mapped_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['product', 'resource_type', 'resource_id']);
        });
    }

    private function createDeployerTables(): void
    {
        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });

        Schema::connection('deployer')->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 20)->default('viewer');
            $table->timestamps();
        });

        Schema::connection('deployer')->create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('invited_by');
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('deployer')->create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('created_by');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::connection('deployer')->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('name');
            $table->string('slug');
            $table->string('type', 20);
            $table->string('branch')->default('main');
            $table->boolean('is_protected')->default(false);
            $table->string('status', 20)->default('ready');
            $table->timestamps();
        });
    }

    private function addCanonicalUser(int $sourceUserId, string $email): void
    {
        DB::connection('core')->table('users')->insert([
            'id' => $this->canonicalUserId($sourceUserId),
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addReconciledUserMap(int $sourceUserId, int $canonicalUserId): void
    {
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => (string) $sourceUserId,
            'canonical_entity' => 'user',
            'canonical_id' => $this->canonicalUserId($canonicalUserId),
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addOrganization(
        int $id,
        int $ownerId,
        string $name = 'Workspace',
        string $slug = 'workspace',
        string $createdAt = '2022-01-01 00:00:00',
    ): void {
        DB::connection('deployer')->table('organizations')->insert([
            'id' => $id,
            'owner_id' => $ownerId,
            'name' => $name,
            'slug' => $slug,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function addOrganizationMember(int $organizationId, int $userId, string $role): void
    {
        DB::connection('deployer')->table('organization_user')->insert([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addProject(
        int $id,
        int $organizationId,
        int $creatorId,
        string $name = 'Project',
        string $slug = 'project',
        string $createdAt = '2022-02-01 00:00:00',
    ): void {
        DB::connection('deployer')->table('projects')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'created_by' => $creatorId,
            'name' => $name,
            'slug' => $slug,
            'description' => 'Imported project',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function addInvitation(int $id, int $organizationId, int $inviterId, string $email, string $expiresAt): void
    {
        DB::connection('deployer')->table('organization_invitations')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'invited_by' => $inviterId,
            'email' => $email,
            'role' => 'developer',
            'token_hash' => 'legacy-invitation-hash',
            'expires_at' => $expiresAt,
            'accepted_at' => null,
            'created_at' => '2021-01-01 00:00:00',
            'updated_at' => '2021-01-01 00:00:00',
        ]);
    }

    private function addEnvironment(
        int $id,
        int $projectId,
        string $name = 'Staging',
        string $slug = 'staging',
        string $type = 'staging',
        bool $protected = false,
    ): void {
        DB::connection('deployer')->table('environments')->insert([
            'id' => $id,
            'project_id' => $projectId,
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'branch' => 'main',
            'is_protected' => $protected,
            'status' => 'ready',
            'created_at' => '2022-03-01 00:00:00',
            'updated_at' => '2022-03-01 00:00:00',
        ]);
    }

    private function canonicalUserId(int $id): string
    {
        return str_pad((string) $id, 26, '0', STR_PAD_LEFT);
    }
}
