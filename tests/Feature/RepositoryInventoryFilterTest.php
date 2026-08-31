<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RepositoryInventoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_combine_search_provider_website_and_latest_status_filters(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $github = $this->provider($owner, 'Owner GitHub', Provider::TYPE_GITHUB);
        $gitlab = $this->provider($owner, 'Owner GitLab', Provider::TYPE_GITLAB);
        $website = $this->website($owner, 'Customer Portal');
        $otherWebsite = $this->website($owner, 'Back Office');

        $matching = $this->repository($owner, $github, $website, 'Customer API');
        $matching->builds()->create(['status' => Build::STATUS_FAILED]);

        $recovered = $this->repository($owner, $github, $website, 'Customer Recovered');
        $recovered->builds()->create(['status' => Build::STATUS_FAILED]);
        $recovered->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $wrongProvider = $this->repository($owner, $gitlab, $website, 'Customer GitLab');
        $wrongProvider->builds()->create(['status' => Build::STATUS_FAILED]);

        $wrongWebsite = $this->repository($owner, $github, $otherWebsite, 'Customer Admin');
        $wrongWebsite->builds()->create(['status' => Build::STATUS_FAILED]);

        $foreignProvider = $this->provider($other, 'Private GitHub', Provider::TYPE_GITHUB);
        $foreignWebsite = $this->website($other, 'Private Customer Portal');
        $foreign = $this->repository($other, $foreignProvider, $foreignWebsite, 'Customer Private');
        $foreign->builds()->create(['status' => Build::STATUS_FAILED]);

        $filters = [
            'search' => 'customer',
            'provider_id' => $github->id,
            'website_id' => $website->id,
            'status' => Build::STATUS_FAILED,
        ];

        $this->actingAs($owner)->get(route('repositories.index', $filters))
            ->assertSuccessful()
            ->assertSee(route('repositories.show', $matching))
            ->assertSee(route('websites.show', $website))
            ->assertSee(route('builds.show', $matching->latestBuild))
            ->assertSee('value="customer"', false)
            ->assertSee('value="'.$github->id.'" selected', false)
            ->assertSee('value="'.$website->id.'" selected', false)
            ->assertSee('value="failed" selected', false)
            ->assertDontSee('Customer Recovered')
            ->assertDontSee('Customer GitLab')
            ->assertDontSee('Customer Admin')
            ->assertDontSee('Customer Private')
            ->assertDontSee('Private GitHub')
            ->assertDontSee('Private Customer Portal');
    }

    public function test_never_deployed_filter_excludes_repositories_with_builds(): void
    {
        $owner = User::factory()->create();
        $provider = $this->provider($owner, 'GitHub', Provider::TYPE_GITHUB);
        $website = $this->website($owner, 'Application');
        $neverDeployed = $this->repository($owner, $provider, $website, 'Never Deployed');
        $deployed = $this->repository($owner, $provider, $website, 'Already Deployed');
        $deployed->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $this->actingAs($owner)->get(route('repositories.index', ['status' => 'none']))
            ->assertSuccessful()
            ->assertSee(route('repositories.show', $neverDeployed))
            ->assertSee('Never deployed')
            ->assertDontSee('Already Deployed');
    }

    public function test_invalid_filters_are_ignored_and_empty_results_can_be_reset(): void
    {
        $owner = User::factory()->create();
        $provider = $this->provider($owner, 'GitHub', Provider::TYPE_GITHUB);
        $website = $this->website($owner, 'Visible Website');
        $this->repository($owner, $provider, $website, 'Visible Repository');

        $this->actingAs($owner)->get(route('repositories.index', [
            'provider_id' => '-1',
            'website_id' => 'invalid',
            'status' => 'exploded',
            'search' => '   ',
        ]))
            ->assertSuccessful()
            ->assertSee('Visible Repository')
            ->assertDontSee('Clear filters');

        $this->actingAs($owner)->get(route('repositories.index', ['search' => 'missing']))
            ->assertSuccessful()
            ->assertSee('No repositories match these filters')
            ->assertSee('Try changing or clearing the selected filters.')
            ->assertSee('Clear filters');
    }

    public function test_filter_state_is_preserved_in_pagination_links(): void
    {
        $owner = User::factory()->create();
        $provider = $this->provider($owner, 'GitHub', Provider::TYPE_GITHUB);
        $website = $this->website($owner, 'Production');

        foreach (range(1, 16) as $index) {
            $repository = $this->repository($owner, $provider, $website, "Production {$index}");
            $repository->builds()->create(['status' => Build::STATUS_QUEUED]);
        }

        $this->actingAs($owner)->get(route('repositories.index', [
            'search' => 'Production',
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'status' => Build::STATUS_QUEUED,
        ]))
            ->assertSuccessful()
            ->assertSee('page=2', false)
            ->assertSee('search=Production', false)
            ->assertSee('provider_id='.$provider->id, false)
            ->assertSee('website_id='.$website->id, false)
            ->assertSee('status=queued', false);
    }

    public function test_repository_with_a_deleted_website_does_not_render_a_broken_target_link(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $provider = $this->provider($owner, 'GitHub', Provider::TYPE_GITHUB);
        $website = $this->website($owner, 'Retired Website');
        $repository = $this->repository($owner, $provider, $website, 'Archived Repository');
        $website->delete();

        $this->actingAs($owner)->get(route('repositories.index'))
            ->assertSuccessful()
            ->assertSee(route('repositories.show', $repository))
            ->assertSee('Deleted website')
            ->assertSee('Retired Website')
            ->assertDontSee(route('websites.show', $website));
    }

    private function provider(User $user, string $name, string $type): Provider
    {
        return $user->providers()->create([
            'name' => $name,
            'provider' => $type,
            'token' => 'secret',
            'description' => "{$name} source provider",
        ]);
    }

    private function website(User $user, string $name): Website
    {
        $server = $user->servers()->create([
            'name' => "{$name} Server",
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return $user->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'url' => str($name)->slug().'.example.com',
            'description' => "{$name} website",
            'environment' => 'APP_ENV=production',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
    }

    private function repository(
        User $user,
        Provider $provider,
        Website $website,
        string $name,
    ): Repository {
        return $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => $name,
            'url' => $provider->repositoryHost().'/example/'.str($name)->slug().'.git',
            'branch' => 'main',
            'description' => "{$name} repository",
        ]);
    }
}
