<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Modules\Deployer\Models\StatusIncident;
use App\Modules\Deployer\Models\StatusPage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceCustomerStatusPagesTest extends TestCase
{
    private string $ownerId;

    private string $workspaceId;

    private int $organizationId = 700;

    private int $websiteId = 901;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        config([
            'platform.products.deployer.enabled' => true,
            'billing.enforce_entitlements' => false,
        ]);
        $this->createTables();

        $this->ownerId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->insertCoreUser($this->ownerId, 'Workspace Owner', 'owner');
        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->ownerId,
            'name' => 'Northstar Studio',
            'slug' => 'northstar-studio',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->ownerId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $membershipId = DB::connection('core')->table('workspace_memberships')->where('workspace_id', $this->workspaceId)->value('id');
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $membershipId,
            'product' => 'deployer',
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('deployer')->table('users')->insert([
            'id' => 801,
            'name' => 'Workspace Owner',
            'email' => 'owner@example.test',
            'password' => 'legacy-hash',
            'current_organization_id' => $this->organizationId,
        ]);
        DB::connection('deployer')->table('organizations')->insert([
            'id' => $this->organizationId,
            'name' => 'Northstar Legacy Organization',
            'owner_id' => 801,
        ]);
        DB::connection('deployer')->table('websites')->insert([
            'id' => $this->websiteId,
            'organization_id' => $this->organizationId,
            'user_id' => 801,
            'name' => 'Public API',
            'deleted_at' => null,
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            [
                'id' => (string) Str::ulid(),
                'source_product' => 'deployer',
                'source_entity' => 'organization',
                'source_id' => (string) $this->organizationId,
                'canonical_entity' => 'workspace',
                'canonical_id' => $this->workspaceId,
                'status' => 'reconciled',
            ],
            [
                'id' => (string) Str::ulid(),
                'source_product' => 'deployer',
                'source_entity' => 'user',
                'source_id' => '801',
                'canonical_entity' => 'user',
                'canonical_id' => $this->ownerId,
                'status' => 'reconciled',
            ],
        ]);
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        URL::forceRootUrl(null);
        foreach (['status_page_website', 'status_subscriptions', 'status_incidents', 'status_pages', 'websites', 'organization_user', 'organizations', 'users'] as $table) {
            Schema::connection('deployer')->dropIfExists($table);
        }
        foreach (['legacy_identity_maps', 'workspace_product_access', 'workspace_memberships', 'workspaces', 'users'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_workspace_owner_can_manage_deployer_pages_and_publish_the_existing_incident_workflow_in_core(): void
    {
        $user = PlatformUser::query()->findOrFail($this->ownerId);
        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.status-pages.index', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Customer status pages')
            ->assertSeeText('Create a status page');

        $this->actingAs($user, 'platform')
            ->from(route('core.workspace.status-pages.index', $this->workspaceId))
            ->post(route('core.workspace.status-pages.store', $this->workspaceId), [
                'name' => 'Northstar Status',
                'slug' => 'northstar-status',
                'description' => 'Public service health for Northstar.',
                'is_published' => '1',
                'website_ids' => [$this->websiteId, 9999],
            ])
            ->assertSessionHasErrors('website_ids');
        $this->assertDatabaseCount('status_pages', 0, 'deployer');

        $this->actingAs($user, 'platform')
            ->post(route('core.workspace.status-pages.store', $this->workspaceId), [
                'name' => 'Northstar Status',
                'slug' => 'northstar-status',
                'description' => 'Public service health for Northstar.',
                'is_published' => '1',
                'website_ids' => [$this->websiteId],
            ])
            ->assertRedirect(route('core.workspace.status-pages.index', $this->workspaceId))
            ->assertSessionHas('success');

        $page = StatusPage::query()->where('slug', 'northstar-status')->sole();
        $this->assertSame($this->organizationId, (int) $page->organization_id);
        $this->assertSame([$this->websiteId], $page->websites()->pluck('websites.id')->map(fn ($id): int => (int) $id)->all());

        $this->actingAs($user, 'platform')
            ->patch(route('core.workspace.status-pages.update', [$this->workspaceId, $page->getKey()]), [
                'name' => 'Northstar Service Status',
                'description' => 'Service health and planned maintenance.',
                'is_published' => '0',
                'website_ids' => [$this->websiteId],
            ])
            ->assertRedirect(route('core.workspace.status-pages.index', $this->workspaceId));
        $this->assertSame('Northstar Service Status', $page->fresh()->name);
        $this->assertFalse($page->fresh()->is_published);
        $this->assertSame('northstar-status', $page->fresh()->slug);

        $this->actingAs($user, 'platform')
            ->post(route('core.workspace.status-pages.incidents.store', $this->workspaceId), [
                'status_page_id' => $page->getKey(),
                'kind' => 'incident',
                'status' => 'investigating',
                'severity' => 'major',
                'title' => 'Elevated API latency',
                'message' => 'We are investigating elevated response times.',
                'root_cause' => 'Internal diagnostic note.',
                'remediation' => 'Scale the affected service.',
                'follow_up' => 'Review after recovery.',
                'starts_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('core.workspace.status-pages.index', $this->workspaceId));

        $incident = StatusIncident::query()->sole();
        $this->assertSame($page->getKey(), $incident->status_page_id);
        $this->assertSame('Internal diagnostic note.', $incident->root_cause);
        $this->assertNull($incident->resolved_at);

        $this->actingAs($user, 'platform')
            ->patch(route('core.workspace.status-pages.incidents.update', [$this->workspaceId, $incident->getKey()]), [
                'kind' => 'incident',
                'status' => 'resolved',
                'severity' => 'major',
                'title' => 'Elevated API latency',
                'message' => 'Response times have returned to normal.',
                'root_cause' => 'Internal diagnostic note.',
                'remediation' => 'Scaled the affected service.',
                'follow_up' => 'Review after recovery.',
                'starts_at' => $incident->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('core.workspace.status-pages.index', $this->workspaceId));

        $this->assertNotNull($incident->fresh()->resolved_at);
        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.status-pages.index', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Elevated API latency')
            ->assertSeeText('Internal diagnostic note.')
            ->assertSeeText('Northstar Service Status');
    }

    public function test_workspace_viewer_can_read_status_history_but_cannot_change_product_records(): void
    {
        $this->seedPage();
        $viewerId = (string) Str::ulid();
        $this->insertCoreUser($viewerId, 'Workspace Viewer', 'viewer');
        $membershipId = (string) Str::ulid();
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $viewerId,
            'role' => 'viewer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $membershipId,
            'product' => 'deployer',
            'role' => 'member',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('users')->insert([
            'id' => 802,
            'name' => 'Workspace Viewer',
            'email' => 'viewer@example.test',
            'password' => 'legacy-hash',
            'current_organization_id' => $this->organizationId,
        ]);
        DB::connection('deployer')->table('organization_user')->insert([
            'organization_id' => $this->organizationId,
            'user_id' => 802,
            'role' => 'developer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => '802',
            'canonical_entity' => 'user',
            'canonical_id' => $viewerId,
            'status' => 'reconciled',
        ]);

        $viewer = PlatformUser::query()->findOrFail($viewerId);
        $this->actingAs($viewer, 'platform')
            ->get(route('core.workspace.status-pages.index', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Northstar Status')
            ->assertDontSeeText('Create a status page');

        $this->actingAs($viewer, 'platform')
            ->post(route('core.workspace.status-pages.store', $this->workspaceId), [
                'name' => 'Unauthorized page',
                'slug' => 'unauthorized-page',
                'is_published' => '1',
                'website_ids' => [$this->websiteId],
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('status_pages', ['slug' => 'unauthorized-page'], 'deployer');
    }

    private function seedPage(): StatusPage
    {
        $pageId = DB::connection('deployer')->table('status_pages')->insertGetId([
            'organization_id' => $this->organizationId,
            'created_by' => 801,
            'name' => 'Northstar Status',
            'slug' => 'northstar-status',
            'description' => 'Service status.',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('status_page_website')->insert([
            'status_page_id' => $pageId,
            'website_id' => $this->websiteId,
            'display_name' => null,
            'position' => 0,
        ]);

        return StatusPage::query()->findOrFail($pageId);
    }

    private function createTables(): void
    {
        foreach (['status_page_website', 'status_subscriptions', 'status_incidents', 'status_pages', 'websites', 'organization_user', 'organizations', 'users'] as $table) {
            Schema::connection('deployer')->dropIfExists($table);
        }
        foreach (['legacy_identity_maps', 'workspace_product_access', 'workspace_memberships', 'workspaces', 'users'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 24)->default('member');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('source_product');
            $table->string('source_entity');
            $table->string('source_id');
            $table->string('canonical_entity')->nullable();
            $table->string('canonical_id', 26)->nullable();
            $table->string('status');
        });
        Schema::connection('deployer')->create('users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('current_organization_id')->nullable();
        });
        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->unsignedBigInteger('owner_id')->nullable();
        });
        Schema::connection('deployer')->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->timestamps();
        });
        Schema::connection('deployer')->create('websites', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::connection('deployer')->create('status_pages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('created_by');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
        Schema::connection('deployer')->create('status_page_website', function (Blueprint $table): void {
            $table->unsignedBigInteger('status_page_id');
            $table->unsignedBigInteger('website_id');
            $table->string('display_name')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['status_page_id', 'website_id']);
        });
        Schema::connection('deployer')->create('status_incidents', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('status_page_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('kind', 20)->default('incident');
            $table->string('status', 30);
            $table->string('severity', 20)->default('minor');
            $table->string('title');
            $table->text('message');
            $table->text('root_cause')->nullable();
            $table->text('remediation')->nullable();
            $table->text('follow_up')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('status_subscriptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('status_page_id');
            $table->text('email');
            $table->char('email_hash', 64);
            $table->char('verification_token_hash', 64)->nullable();
            $table->text('unsubscribe_token');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    private function insertCoreUser(string $id, string $name, string $localPart): void
    {
        DB::connection('core')->table('users')->insert([
            'id' => $id,
            'name' => $name,
            'email' => $localPart.'@example.test',
            'email_normalized' => $localPart.'@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
