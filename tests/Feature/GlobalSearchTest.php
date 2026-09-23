<?php

namespace Tests\Feature;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\Core\DeployerWorkspaceSearchProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_requires_a_verified_account(): void
    {
        $this->get(route('search.index', ['q' => 'demo']))->assertRedirect(route('login'));

        $user = User::factory()->unverified()->create();
        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'demo']))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_global_search_returns_only_owner_metadata_across_every_supported_resource(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $resources = $this->resources($owner, 'Needle', 'owner');
        $other = User::factory()->create();
        $this->resources($other, 'Needle Foreign', 'foreign');

        $response = $this->actingAs($owner)->get(route('search.index', ['q' => '  Needle  ']));

        $response
            ->assertSuccessful()
            ->assertViewHas('query', 'Needle')
            ->assertViewHas('groups', function (array $groups): bool {
                return array_keys($groups) === [
                    'projects',
                    'websites',
                    'servers',
                    'repositories',
                    'providers',
                    'recipes',
                    'builds',
                ] && collect($groups)->every(fn (array $group): bool => $group['results']->count() === 1
                    && $group['has_more'] === false);
            })
            ->assertSee('7 results shown')
            ->assertSee(route('projects.show', $resources['project']))
            ->assertSee(route('websites.show', $resources['website']))
            ->assertSee(route('servers.show', $resources['server']))
            ->assertSee(route('repositories.show', $resources['repository']))
            ->assertSee(route('providers.show', $resources['provider']))
            ->assertSee(route('recipes.show', $resources['recipe']))
            ->assertSee(route('builds.show', $resources['build']))
            ->assertDontSee('foreign', false)
            ->assertDontSee('owner-provider-token', false)
            ->assertDontSee('owner-server-password', false)
            ->assertDontSee('owner-private-key', false)
            ->assertDontSee('OWNER_ENVIRONMENT_SECRET', false)
            ->assertDontSee('owner-database-password', false)
            ->assertDontSee('owner-build-command', false)
            ->assertDontSee('owner-webhook-secret', false)
            ->assertDontSee('owner-recipe-script', false);
    }

    public function test_blank_and_missing_searches_render_guidance_without_querying_results(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('search.index', ['q' => '   ']));

        $response
            ->assertSuccessful()
            ->assertViewHas('query', '')
            ->assertViewHas('groups', [])
            ->assertSee('Search your account')
            ->assertSee('Enter a resource name, URL, IP address, revision, or description to begin.');
    }

    public function test_workspace_fragment_reuses_scoped_search_results_without_rendering_a_full_page(): void
    {
        $owner = User::factory()->create();
        $recipe = $owner->recipes()->create([
            'name' => 'Dialog recipe',
            'description' => 'Workspace search fragment fixture',
            'script' => 'echo private',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('search.index', ['q' => 'Dialog', 'fragment' => 'workspace']))
            ->assertSuccessful()
            ->assertViewIs('search._workspace-results')
            ->assertSee('Workspace search results')
            ->assertSee($recipe->name)
            ->assertSee(route('recipes.show', $recipe))
            ->assertDontSee('<html', false)
            ->assertDontSee('echo private', false);

        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_deployer_workspace_fragment_includes_authorized_core_project_results(): void
    {
        $this->createCoreSearchSchema();
        $user = User::factory()->create();
        $coreUserId = (string) Str::ulid();
        $workspaceId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        $projectId = (string) Str::ulid();

        DB::connection('core')->table('users')->insert([
            'id' => $coreUserId,
            'name' => $user->name,
            'email' => $user->email,
            'email_normalized' => mb_strtolower($user->email),
            'password' => 'not-a-real-password-hash',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspaces')->insert([
            'id' => $workspaceId,
            'owner_user_id' => $coreUserId,
            'name' => 'Unified workspace',
            'slug' => 'unified-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $workspaceId,
            'user_id' => $coreUserId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $coreUserId,
            'name' => 'Unified search catalog',
            'slug' => 'unified-search-catalog',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'user_id' => $coreUserId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            [
                'source_product' => 'deployer',
                'source_entity' => 'user',
                'source_id' => (string) $user->getKey(),
                'canonical_entity' => 'user',
                'canonical_id' => $coreUserId,
                'status' => 'reconciled',
            ],
            [
                'source_product' => 'deployer',
                'source_entity' => 'organization',
                'source_id' => (string) $user->current_organization_id,
                'canonical_entity' => 'workspace',
                'canonical_id' => $workspaceId,
                'status' => 'reconciled',
            ],
        ]);

        try {
            $response = $this->actingAs($user)
                ->get(route('search.index', ['q' => 'Unified search', 'fragment' => 'workspace']));

            $response->assertSuccessful()
                ->assertViewIs('search._workspace-results')
                ->assertSee('Unified search catalog')
                ->assertSee(route('core.projects.show', [$workspaceId, $projectId]));
        } finally {
            $this->dropCoreSearchSchema();
        }
    }

    public function test_core_search_links_deployer_resources_with_their_source_organization_context(): void
    {
        $user = User::factory()->create();
        $resources = $this->resources($user, 'Context search', 'owner');
        $server = $resources['server'];
        $build = $resources['build'];
        $platformUser = (new PlatformUser)->forceFill([
            'id' => (string) Str::ulid(),
            'name' => 'Search user',
            'email' => 'context-search@example.test',
            'status' => 'active',
        ]);
        $workspace = (new CoreWorkspace)->forceFill(['id' => (string) Str::ulid()]);

        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->string('source_product');
            $table->string('source_entity');
            $table->string('source_id');
            $table->string('canonical_entity');
            $table->string('canonical_id');
            $table->string('status');
        });

        try {
            DB::connection('core')->table('legacy_identity_maps')->insert([
                [
                    'source_product' => 'deployer',
                    'source_entity' => 'user',
                    'source_id' => (string) $user->getKey(),
                    'canonical_entity' => 'user',
                    'canonical_id' => (string) $platformUser->getKey(),
                    'status' => 'reconciled',
                ],
                [
                    'source_product' => 'deployer',
                    'source_entity' => 'organization',
                    'source_id' => (string) $user->current_organization_id,
                    'canonical_entity' => 'workspace',
                    'canonical_id' => (string) $workspace->getKey(),
                    'status' => 'reconciled',
                ],
            ]);

            $results = (new DeployerWorkspaceSearchProvider(new LegacyIdentityResolver))
                ->search($platformUser, $workspace, 'Context search');

            $this->assertSame([
                route('servers.show', [
                    'server' => $server->getKey(),
                    'organization_id' => $user->current_organization_id,
                ]),
                route('builds.show', [
                    'build' => $build->getKey(),
                    'organization_id' => $user->current_organization_id,
                ]),
            ], array_map(static fn ($result): string => $result->url, $results));
        } finally {
            Schema::connection('core')->dropIfExists('legacy_identity_maps');
        }
    }

    public function test_each_group_is_limited_and_links_to_the_filtered_inventory_for_more_results(): void
    {
        $owner = User::factory()->create();
        foreach (range(1, 6) as $position) {
            $owner->recipes()->create([
                'name' => "Limit Recipe {$position}",
                'description' => 'Global search limit fixture',
                'script' => "secret-script-{$position}",
            ]);
        }

        $this->actingAs($owner)->get(route('search.index', ['q' => 'Limit']))
            ->assertSuccessful()
            ->assertViewHas('groups', fn (array $groups): bool => $groups['recipes']['results']->count() === 5
                && $groups['recipes']['has_more'] === true
                && $groups['recipes']['more_url'] === route('recipes.index', ['search' => 'Limit']))
            ->assertSee('5 results shown')
            ->assertSee('View more')
            ->assertSee(route('recipes.index', ['search' => 'Limit']))
            ->assertDontSee('secret-script', false);
    }

    public function test_search_results_have_jump_links_and_group_context(): void
    {
        $owner = User::factory()->create();
        $resources = $this->resources($owner, 'Jumpable', 'owner');

        $this->actingAs($owner)->get(route('search.index', ['q' => 'Jumpable']))
            ->assertSuccessful()
            ->assertSee('Search result groups')
            ->assertSee('href="#search-group-projects"', false)
            ->assertSee('id="search-group-projects"', false)
            ->assertSee('id="search-group-heading-projects"', false)
            ->assertSee('Applications')
            ->assertSee($resources['project']->name);
    }

    public function test_repository_name_search_includes_its_builds_and_preserves_owner_scoping(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $owned = $this->resources($owner, 'Release Train', 'owner');
        $foreign = $this->resources(User::factory()->create(), 'Release Train Foreign', 'foreign');

        $owned['build']->update(['commit_message' => 'Unrelated owned commit']);
        $foreign['build']->update(['commit_message' => 'Unrelated foreign commit']);

        $this->actingAs($owner)->get(route('search.index', ['q' => 'Release Train']))
            ->assertSuccessful()
            ->assertViewHas('groups', fn (array $groups): bool => $groups['builds']['results']->count() === 1
                && $groups['builds']['results']->first()['url'] === route('builds.show', $owned['build']))
            ->assertSee(route('builds.show', $owned['build']))
            ->assertDontSee(route('builds.show', $foreign['build']));
    }

    public function test_sql_wildcard_characters_are_searched_as_literal_text(): void
    {
        $owner = User::factory()->create();
        $percent = $owner->recipes()->create([
            'name' => 'Deploy 100% safely',
            'description' => 'Literal percent fixture',
            'script' => 'echo percent',
        ]);
        $underscore = $owner->recipes()->create([
            'name' => 'release_candidate',
            'description' => 'Literal underscore fixture',
            'script' => 'echo underscore',
        ]);
        $bang = $owner->recipes()->create([
            'name' => 'Ship it!',
            'description' => 'Literal escape fixture',
            'script' => 'echo bang',
        ]);
        $owner->recipes()->create([
            'name' => 'Ordinary recipe',
            'description' => 'Must not match special-character searches',
            'script' => 'echo ordinary',
        ]);
        User::factory()->create()->recipes()->create([
            'name' => 'Foreign 100% recipe',
            'description' => 'Must remain private',
            'script' => 'echo foreign',
        ]);

        foreach ([
            '%' => $percent,
            '_' => $underscore,
            '!' => $bang,
        ] as $query => $expected) {
            $this->actingAs($owner)->get(route('search.index', ['q' => $query]))
                ->assertSuccessful()
                ->assertViewHas('groups', fn (array $groups): bool => collect($groups)
                    ->sum(fn (array $group): int => $group['results']->count()) === 1)
                ->assertSee(route('recipes.show', $expected))
                ->assertDontSee('Ordinary recipe')
                ->assertDontSee('Foreign 100% recipe');
        }
    }

    public function test_view_more_inventory_filters_preserve_literal_search_semantics(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $literal = $this->resources($owner, 'Exact 100%', 'literal');
        $ordinary = $this->resources($owner, 'Ordinary', 'ordinary');

        foreach ([
            route('websites.index', ['search' => '%']) => [$literal['website'], $ordinary['website'], 'websites.show'],
            route('servers.index', ['search' => '%']) => [$literal['server'], $ordinary['server'], 'servers.show'],
            route('repositories.index', ['search' => '%']) => [$literal['repository'], $ordinary['repository'], 'repositories.show'],
            route('providers.index', ['search' => '%']) => [$literal['provider'], $ordinary['provider'], 'providers.show'],
            route('recipes.index', ['search' => '%']) => [$literal['recipe'], $ordinary['recipe'], 'recipes.show'],
            route('builds.index', ['search' => '%']) => [$literal['build'], $ordinary['build'], 'builds.show'],
        ] as $url => [$expected, $unexpected, $showRoute]) {
            $this->actingAs($owner)->get($url)
                ->assertSuccessful()
                ->assertSee(route($showRoute, $expected))
                ->assertDontSee(route($showRoute, $unexpected));
        }
    }

    public function test_authenticated_sidebar_contains_the_global_search_entry_point(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee(route('search.index'))
            ->assertSee('Search or jump to…');
    }

    /** @return array<string, mixed> */
    private function resources(User $user, string $label, string $secretPrefix): array
    {
        $project = $user->currentOrganization->projects()->create([
            'created_by' => $user->id,
            'name' => "{$label} Application",
            'slug' => str($label)->slug().'-application',
            'description' => "{$label} application",
            'preset' => 'custom',
        ]);
        $provider = $user->providers()->create([
            'name' => "{$label} Provider",
            'provider' => Provider::TYPE_GITHUB,
            'token' => "{$secretPrefix}-provider-token",
            'description' => "{$label} source control",
            'connection_status' => Provider::CONNECTION_HEALTHY,
        ]);
        $server = $user->servers()->create([
            'name' => "{$label} Server",
            'provider_id' => $provider->id,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'password' => "{$secretPrefix}-server-password",
            'ssh_private_key' => "{$secretPrefix}-private-key",
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => "{$label} Website",
            'url' => str($label)->slug().'.example.test',
            'description' => "{$label} web application",
            'provisioning_status' => Website::STATUS_ACTIVE,
            'environment' => strtoupper($secretPrefix).'_ENVIRONMENT_SECRET=true',
            'database_password' => "{$secretPrefix}-database-password",
        ]);
        $repository = $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => "{$label} Repository",
            'url' => 'github.com/example/'.str($label)->slug().'.git',
            'description' => "{$label} repository",
            'build_commands' => "{$secretPrefix}-build-command",
            'webhook_secret' => "{$secretPrefix}-webhook-secret",
        ]);
        $recipe = $user->recipes()->create([
            'name' => "{$label} Recipe",
            'description' => "{$label} provisioning recipe",
            'script' => "{$secretPrefix}-recipe-script",
        ]);
        $build = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat($secretPrefix === 'owner' ? 'a' : 'b', 40),
            'commit_message' => "{$label} release",
        ]);

        return compact('project', 'provider', 'server', 'website', 'repository', 'recipe', 'build');
    }

    private function createCoreSearchSchema(): void
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
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('created_by_user_id', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('status');
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
        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->string('source_product');
            $table->string('source_entity');
            $table->string('source_id');
            $table->string('canonical_entity');
            $table->string('canonical_id');
            $table->string('status');
        });
    }

    private function dropCoreSearchSchema(): void
    {
        foreach ([
            'project_memberships',
            'projects',
            'workspace_product_access',
            'workspace_memberships',
            'workspaces',
            'legacy_identity_maps',
            'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }
    }
}
