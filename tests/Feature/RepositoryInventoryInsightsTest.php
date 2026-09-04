<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryInventoryInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_latest_deployment_and_webhook_counts_without_foreign_or_secret_data(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        [$provider, $website] = $this->infrastructure($owner, 'Owner');
        [$foreignProvider, $foreignWebsite] = $this->infrastructure($other, 'Foreign');
        $this->repository($owner, $provider, $website, 'Never deployed', false);
        $queued = $this->repository($owner, $provider, $website, 'Queued latest', true);
        $queued->builds()->create(['status' => Build::STATUS_QUEUED]);
        $running = $this->repository($owner, $provider, $website, 'Running latest', false);
        $running->builds()->create(['status' => Build::STATUS_RUNNING]);
        $recovered = $this->repository($owner, $provider, $website, 'Recovered latest', true);
        $recovered->builds()->create(['status' => Build::STATUS_FAILED]);
        $recovered->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $failed = $this->repository($owner, $provider, $website, 'Failed latest', true);
        $failed->builds()->create(['status' => Build::STATUS_FAILED]);
        $canceled = $this->repository($owner, $provider, $website, 'Canceled latest', false);
        $canceled->builds()->create(['status' => Build::STATUS_CANCELED]);
        $foreign = $this->repository($other, $foreignProvider, $foreignWebsite, 'Foreign private repository', true);
        $foreign->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $this->actingAs($owner)->get(route('repositories.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 6,
                'never_deployed' => 1,
                'active' => 2,
                'succeeded' => 1,
                'failed' => 1,
                'webhooks' => 3,
            ])
            ->assertSee('Matching repositories')
            ->assertSee('Never deployed')
            ->assertSee('Active deployments')
            ->assertSee('Latest succeeded')
            ->assertSee('Latest failed')
            ->assertSee('Push webhooks')
            ->assertDontSee('Foreign private repository')
            ->assertDontSee('repository-build-command-secret');
    }

    public function test_metrics_apply_search_provider_website_and_latest_status_filters(): void
    {
        $owner = User::factory()->create();
        [$provider, $website] = $this->infrastructure($owner, 'Owner');
        [$otherProvider, $otherWebsite] = $this->infrastructure($owner, 'Other');
        $matching = $this->repository($owner, $provider, $website, 'Customer failure', true);
        $matching->builds()->create(['status' => Build::STATUS_FAILED]);
        $recovered = $this->repository($owner, $provider, $website, 'Customer recovered', true);
        $recovered->builds()->create(['status' => Build::STATUS_FAILED]);
        $recovered->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $wrongProvider = $this->repository($owner, $otherProvider, $website, 'Customer wrong provider', true);
        $wrongProvider->builds()->create(['status' => Build::STATUS_FAILED]);
        $wrongWebsite = $this->repository($owner, $provider, $otherWebsite, 'Customer wrong website', true);
        $wrongWebsite->builds()->create(['status' => Build::STATUS_FAILED]);

        $this->actingAs($owner)->get(route('repositories.index', [
            'search' => 'Customer',
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'status' => Build::STATUS_FAILED,
        ]))
            ->assertSuccessful()
            ->assertViewHas('repositories', fn ($repositories): bool => $repositories->count() === 1
                && $repositories->sole()->id === $matching->id)
            ->assertViewHas('metrics', [
                'total' => 1,
                'never_deployed' => 0,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 1,
                'webhooks' => 1,
            ]);
    }

    public function test_never_deployed_and_empty_filters_have_explicit_zero_metrics(): void
    {
        $owner = User::factory()->create();
        [$provider, $website] = $this->infrastructure($owner, 'Owner');
        $never = $this->repository($owner, $provider, $website, 'First deployment pending', false);

        $this->actingAs($owner)->get(route('repositories.index', ['status' => 'none']))
            ->assertSuccessful()
            ->assertViewHas('repositories', fn ($repositories): bool => $repositories->sole()->id === $never->id)
            ->assertViewHas('metrics', [
                'total' => 1,
                'never_deployed' => 1,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'webhooks' => 0,
            ]);

        $this->actingAs($owner)->get(route('repositories.index', ['search' => 'missing-repository']))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'never_deployed' => 0,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'webhooks' => 0,
            ])
            ->assertSee('No repositories match these filters');
    }

    /** @return array{Provider, Website} */
    private function infrastructure(User $user, string $prefix): array
    {
        $provider = $user->providers()->create([
            'name' => "{$prefix} GitHub",
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-token',
            'description' => 'Source provider',
        ]);
        $server = $user->servers()->create([
            'name' => "{$prefix} server",
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => "{$prefix} website",
            'url' => str($prefix)->slug().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$provider, $website];
    }

    private function repository(
        User $user,
        Provider $provider,
        Website $website,
        string $name,
        bool $webhookEnabled,
    ): Repository {
        return $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => $name,
            'url' => 'github.com/example/'.str($name)->slug().'.git',
            'branch' => 'main',
            'description' => 'Repository',
            'build_commands' => 'repository-build-command-secret',
            'webhook_enabled' => $webhookEnabled,
        ]);
    }
}
