<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\WorkspaceSearchProvider;
use App\Core\Data\Search\WorkspaceSearchResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\Search\WorkspaceSearch;
use App\Core\Services\Search\WorkspaceSearchProviderRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceSearchTest extends TestCase
{
    private string $userId;

    private string $workspaceId;

    private string $membershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();

        DB::connection('core')->table('users')->insert([
            'id' => $this->userId,
            'name' => 'Search user',
            'email' => 'search@example.test',
            'email_normalized' => 'search@example.test',
            'password' => 'not-a-real-password-hash',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->userId,
            'name' => 'Search workspace',
            'slug' => 'search-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $this->membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        foreach ([
            'project_memberships',
            'projects',
            'workspace_product_access',
            'workspace_memberships',
            'workspaces',
            'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_search_returns_only_workspace_projects_the_user_can_open_and_authorized_product_results(): void
    {
        $visibleProjectId = $this->project('Checkout portal', true);
        $hiddenProjectId = $this->project('Checkout private', false);
        $this->grant('monitor');
        $this->grant('analytics');

        $registry = new WorkspaceSearchProviderRegistry;
        $registry->register('monitor', new class implements WorkspaceSearchProvider
        {
            public function search(PlatformUser $user, Workspace $workspace, string $query): array
            {
                return [new WorkspaceSearchResult('Incident', 'Incident #17', 'Open', '/monitor/incidents/17')];
            }
        });
        $registry->register('analytics', new class implements WorkspaceSearchProvider
        {
            public function search(PlatformUser $user, Workspace $workspace, string $query): array
            {
                throw new QueryException('analytics', 'select', [], new \RuntimeException('offline'));
            }
        });

        $result = (new WorkspaceSearch($registry, app(WorkspaceProjectAccess::class)))
            ->forWorkspace($this->user(), $this->workspace(), 'Checkout');

        $this->assertSame('Checkout', $result['query']);
        $this->assertSame(['core', 'monitor'], array_column($result['groups'], 'product'));
        $this->assertSame(1, $result['groups'][0]['count']);
        $this->assertSame('Checkout portal', $result['groups'][0]['results'][0]['title']);
        $this->assertSame(route('core.projects.show', [$this->workspaceId, $visibleProjectId]), $result['groups'][0]['results'][0]['url']);
        $this->assertSame('Incident #17', $result['groups'][1]['results'][0]['title']);
        $this->assertSame([['product' => 'analytics', 'label' => 'Analytics']], $result['unavailable']);
        $this->assertStringNotContainsString($hiddenProjectId, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_search_skips_a_product_provider_when_the_workspace_grant_is_missing_or_revoked(): void
    {
        $this->project('Checkout portal', true);
        $registry = new WorkspaceSearchProviderRegistry;
        $registry->register('monitor', new class implements WorkspaceSearchProvider
        {
            public function search(PlatformUser $user, Workspace $workspace, string $query): array
            {
                throw new \LogicException('The provider must not run without a current product grant.');
            }
        });

        $result = (new WorkspaceSearch($registry, app(WorkspaceProjectAccess::class)))
            ->forWorkspace($this->user(), $this->workspace(), 'Checkout');

        $this->assertSame(['core'], array_column($result['groups'], 'product'));
        $this->assertSame([], $result['unavailable']);

        $this->grant('monitor', revoked: true);
        $result = (new WorkspaceSearch($registry, app(WorkspaceProjectAccess::class)))
            ->forWorkspace($this->user(), $this->workspace(), 'Checkout');

        $this->assertSame(['core'], array_column($result['groups'], 'product'));
        $this->assertSame([], $result['unavailable']);

        DB::connection('core')->table('workspace_memberships')
            ->where('id', $this->membershipId)
            ->update(['expires_at' => now()->subSecond()]);
        $result = (new WorkspaceSearch($registry, app(WorkspaceProjectAccess::class)))
            ->forWorkspace($this->user(), $this->workspace(), 'Checkout');

        $this->assertSame([], $result['groups']);
        $this->assertSame([], $result['unavailable']);
    }

    public function test_search_requires_two_characters_and_escapes_sql_wildcards(): void
    {
        $this->project('Release 100% complete', true);
        $search = new WorkspaceSearch(new WorkspaceSearchProviderRegistry, app(WorkspaceProjectAccess::class));

        $this->assertSame([], $search->forWorkspace($this->user(), $this->workspace(), '%')['groups']);
        $literal = $search->forWorkspace($this->user(), $this->workspace(), '100%');
        $wildcard = $search->forWorkspace($this->user(), $this->workspace(), '100x');

        $this->assertSame(['Release 100% complete'], array_column($literal['groups'][0]['results'], 'title'));
        $this->assertSame([], $wildcard['groups']);
    }

    public function test_search_endpoint_requires_workspace_membership_and_disables_shared_caching(): void
    {
        $this->actingAs($this->user(), 'platform');

        $response = $this->getJson(route('core.workspace.search', [
            'workspace' => $this->workspaceId,
            'q' => 'checkout',
        ]));

        $response->assertOk()
            ->assertJsonPath('query', 'checkout')
            ->assertJsonPath('groups', []);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $otherWorkspaceId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $otherWorkspaceId,
            'owner_user_id' => (string) Str::ulid(),
            'name' => 'Another workspace',
            'slug' => 'another-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson(route('core.workspace.search', [
            'workspace' => $otherWorkspaceId,
            'q' => 'checkout',
        ]))->assertNotFound();
    }

    private function project(string $name, bool $member): string
    {
        $projectId = (string) Str::ulid();
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($member) {
            DB::connection('core')->table('project_memberships')->insert([
                'id' => (string) Str::ulid(),
                'project_id' => $projectId,
                'user_id' => $this->userId,
                'role' => 'owner',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $projectId;
    }

    private function grant(string $product, bool $revoked = false): void
    {
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $this->membershipId,
            'product' => $product,
            'role' => 'member',
            'status' => 'active',
            'granted_at' => now(),
            'revoked_at' => $revoked ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(): PlatformUser
    {
        return PlatformUser::query()->findOrFail($this->userId);
    }

    private function workspace(): Workspace
    {
        return Workspace::query()->findOrFail($this->workspaceId);
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('email');
            $table->string('email_normalized');
            $table->string('password');
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product');
            $table->string('role');
            $table->string('status');
            $table->char('granted_by_user_id', 26)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('created_by_user_id', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('user_id', 26);
            $table->string('role');
            $table->string('status');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }
}
