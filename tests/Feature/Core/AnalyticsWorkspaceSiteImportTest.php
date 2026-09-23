<?php

namespace Tests\Feature\Core;

use App\Core\Services\Migration\ImportAnalyticsWorkspacesAndSitesIntoCore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AnalyticsWorkspaceSiteImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        $this->createAnalyticsTables();
    }

    protected function tearDown(): void
    {
        foreach (['sites', 'invitations', 'workspace_user', 'workspaces', 'users'] as $table) {
            Schema::connection('analytics')->dropIfExists($table);
        }

        foreach ([
            'legacy_identity_maps', 'project_resources', 'project_products', 'project_memberships',
            'projects', 'workspace_invitations', 'workspace_product_access', 'workspace_memberships',
            'workspaces', 'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_preview_is_read_only_and_counts_reconciled_workspaces_and_sites(): void
    {
        $this->addReconciledUser(1, '01J8AA00000000000000000000', 'owner@example.test');
        $this->addAnalyticsWorkspace(10, 'Acme Analytics', 'acme');
        $this->addAnalyticsMembership(10, 1, 'owner');
        $this->addAnalyticsSite(50, 10, 'Storefront', 'storefront', 'public-storefront');

        $report = app(ImportAnalyticsWorkspacesAndSitesIntoCore::class)->run();

        $this->assertSame(1, $report['workspaces_seen']);
        $this->assertSame(1, $report['workspaces_ready']);
        $this->assertSame(1, $report['sites_seen']);
        $this->assertSame(1, $report['sites_ready']);
        $this->assertSame(0, $report['workspaces_imported']);
        $this->assertSame(0, $report['sites_imported']);
        $this->assertDatabaseCount('workspaces', 0, 'core');
        $this->assertDatabaseCount('projects', 0, 'core');
        $this->assertDatabaseCount('legacy_identity_maps', 1, 'core');
    }

    public function test_apply_preserves_workspace_roles_invitations_and_site_resource_ids_idempotently(): void
    {
        $this->addReconciledUser(10, '01J8AA00000000000000000000', 'owner@example.test');
        $this->addReconciledUser(11, '01J8BB00000000000000000000', 'viewer@example.test');
        $this->addAnalyticsWorkspace(20, 'Acme Analytics', 'acme');
        $this->addAnalyticsMembership(20, 10, 'owner');
        $this->addAnalyticsMembership(20, 11, 'viewer');
        $this->addAnalyticsInvitation(30, 20, 10, 'invitee@example.test', 'viewer');
        $this->addAnalyticsSite(40, 20, 'Storefront', 'storefront', 'public-storefront');

        $service = app(ImportAnalyticsWorkspacesAndSitesIntoCore::class);
        $firstRun = $service->run(apply: true);
        $workspaceMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'analytics')->where('source_entity', 'workspace')->where('source_id', '20')->first();
        $siteMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'analytics')->where('source_entity', 'site')->where('source_id', '40')->first();
        $project = DB::connection('core')->table('projects')->where('id', $siteMap->canonical_id)->first();
        $resource = DB::connection('core')->table('project_resources')
            ->where('product', 'analytics')->where('resource_type', 'site')->where('resource_id', '40')->first();
        $resourceMetadata = json_decode($resource->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $firstRun['workspaces_imported']);
        $this->assertSame(1, $firstRun['sites_imported']);
        $this->assertSame(1, $firstRun['invitations_imported']);
        $this->assertSame('reconciled', $workspaceMap->status);
        $this->assertSame('workspace', $workspaceMap->canonical_entity);
        $this->assertSame('reconciled', $siteMap->status);
        $this->assertSame('project', $siteMap->canonical_entity);
        $this->assertSame('active', $project->status);
        $this->assertSame('01J8AA00000000000000000000', $project->created_by_user_id);
        $this->assertSame('public-storefront', $resource->resource_public_id);
        $this->assertSame('active', $resource->status);
        $this->assertSame(['storefront.example.test'], $resourceMetadata['domains']);
        $this->assertSame('America/Los_Angeles', $resourceMetadata['timezone']);
        $this->assertArrayNotHasKey('verification_token', $resourceMetadata);
        $this->assertSame(2, DB::connection('core')->table('workspace_memberships')->count());
        $this->assertSame(2, DB::connection('core')->table('workspace_product_access')->count());
        $this->assertSame(2, DB::connection('core')->table('project_memberships')->count());
        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $workspaceMap->canonical_id,
            'email_normalized' => 'invitee@example.test',
            'status' => 'pending',
            'token_hash' => 'invite-token-hash',
        ], 'core');

        $secondRun = $service->run(apply: true);

        $this->assertSame(1, $secondRun['workspaces_already_mapped']);
        $this->assertSame(1, $secondRun['sites_already_mapped']);
        $this->assertSame(0, $secondRun['workspaces_imported']);
        $this->assertSame(0, $secondRun['sites_imported']);
        $this->assertDatabaseCount('workspaces', 1, 'core');
        $this->assertDatabaseCount('projects', 1, 'core');
    }

    public function test_ambiguous_workspace_owner_is_held_and_retried_after_roles_are_corrected(): void
    {
        $this->addReconciledUser(1, '01J8AA00000000000000000000', 'one@example.test');
        $this->addReconciledUser(2, '01J8BB00000000000000000000', 'two@example.test');
        $this->addAnalyticsWorkspace(70, 'Shared Analytics', 'shared');
        $this->addAnalyticsMembership(70, 1, 'owner');
        $this->addAnalyticsMembership(70, 2, 'owner');
        $this->addAnalyticsSite(71, 70, 'Shared site', 'shared-site', 'public-shared');

        $service = app(ImportAnalyticsWorkspacesAndSitesIntoCore::class);
        $firstRun = $service->run(apply: true);

        $this->assertSame(1, $firstRun['workspaces_blocked']);
        $this->assertSame(1, $firstRun['sites_blocked']);
        $this->assertSame(0, $firstRun['workspaces_imported']);
        $this->assertDatabaseCount('workspaces', 0, 'core');
        $this->assertDatabaseCount('projects', 0, 'core');

        DB::connection('analytics')->table('workspace_user')
            ->where('workspace_id', 70)->where('user_id', 2)->update(['role' => 'admin']);

        $retry = $service->run(apply: true);

        $this->assertSame(1, $retry['workspaces_imported']);
        $this->assertSame(1, $retry['sites_imported']);
        $workspaceMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'analytics')->where('source_entity', 'workspace')->where('source_id', '70')->first();
        $workspaceMetadata = json_decode($workspaceMap->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('reconciled', $workspaceMap->status);
        $this->assertContains(
            'workspace_has_multiple_owners',
            $workspaceMetadata['review_history'][0]['reason_codes'],
        );
        $this->assertDatabaseCount('workspaces', 1, 'core');
        $this->assertDatabaseCount('projects', 1, 'core');
    }

    public function test_soft_deleted_site_is_preserved_as_an_archived_project_resource(): void
    {
        $this->addReconciledUser(80, '01J8AA00000000000000000000', 'owner@example.test');
        $this->addAnalyticsWorkspace(80, 'Archived Analytics', 'archived');
        $this->addAnalyticsMembership(80, 80, 'owner');
        $this->addAnalyticsSite(81, 80, 'Old site', 'old-site', 'public-old-site');
        DB::connection('analytics')->table('sites')->where('id', 81)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        app(ImportAnalyticsWorkspacesAndSitesIntoCore::class)->run(apply: true);

        $siteMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'analytics')->where('source_entity', 'site')->where('source_id', '81')->first();
        $project = DB::connection('core')->table('projects')->where('id', $siteMap->canonical_id)->first();
        $product = DB::connection('core')->table('project_products')->where('project_id', $project->id)->first();
        $resource = DB::connection('core')->table('project_resources')->where('project_id', $project->id)->first();

        $this->assertSame('archived', $project->status);
        $this->assertNotNull($project->archived_at);
        $this->assertSame('inactive', $product->status);
        $this->assertSame('archived', $resource->status);
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

    private function createAnalyticsTables(): void
    {
        Schema::connection('analytics')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
        Schema::connection('analytics')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::connection('analytics')->create('workspace_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 32);
            $table->timestamps();
        });
        Schema::connection('analytics')->create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->string('email');
            $table->string('role', 32);
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('analytics')->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('slug');
            $table->string('public_id', 32);
            $table->json('domains');
            $table->json('excluded_paths')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->boolean('collection_enabled')->default(true);
            $table->timestamp('collection_paused_at')->nullable();
            $table->string('verification_token', 64);
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
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => (string) $sourceId,
            'canonical_entity' => 'user',
            'canonical_id' => $canonicalId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('analytics')->table('users')->insert([
            'id' => $sourceId,
            'name' => 'Mapped user '.$sourceId,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addAnalyticsWorkspace(int $id, string $name, string $slug): void
    {
        DB::connection('analytics')->table('workspaces')->insert([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addAnalyticsMembership(int $workspaceId, int $userId, string $role): void
    {
        DB::connection('analytics')->table('workspace_user')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addAnalyticsInvitation(int $id, int $workspaceId, int $invitedBy, string $email, string $role): void
    {
        DB::connection('analytics')->table('invitations')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'invited_by' => $invitedBy,
            'email' => $email,
            'role' => $role,
            'token_hash' => 'invite-token-hash',
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addAnalyticsSite(int $id, int $workspaceId, string $name, string $slug, string $publicId): void
    {
        DB::connection('analytics')->table('sites')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'name' => $name,
            'slug' => $slug,
            'public_id' => $publicId,
            'domains' => json_encode(['storefront.example.test'], JSON_THROW_ON_ERROR),
            'excluded_paths' => json_encode(['/admin/*'], JSON_THROW_ON_ERROR),
            'timezone' => 'America/Los_Angeles',
            'verified_at' => now(),
            'last_event_at' => now(),
            'last_processed_at' => now(),
            'collection_enabled' => true,
            'verification_token' => 'secret-site-verification-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
