<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
                    'websites',
                    'servers',
                    'repositories',
                    'providers',
                    'recipes',
                    'builds',
                ] && collect($groups)->every(fn (array $group): bool => $group['results']->count() === 1
                    && $group['has_more'] === false);
            })
            ->assertSee('6 results shown')
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

    public function test_authenticated_sidebar_contains_the_global_search_entry_point(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee(route('search.index'))
            ->assertSee('Search account');
    }

    /** @return array<string, mixed> */
    private function resources(User $user, string $label, string $secretPrefix): array
    {
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

        return compact('provider', 'server', 'website', 'repository', 'recipe', 'build');
    }
}
