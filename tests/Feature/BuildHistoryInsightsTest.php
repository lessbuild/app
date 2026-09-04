<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildHistoryInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_filter_aware_deployment_outcomes_and_success_rate(): void
    {
        [$owner, $repository] = $this->repository('Owner');
        [, $foreignRepository] = $this->repository('Foreign');
        $latestAt = now()->subMinute();
        $this->build($repository, Build::STATUS_QUEUED, now()->subDays(7));
        $this->build($repository, Build::STATUS_RUNNING, now()->subDays(6));
        $this->build($repository, Build::STATUS_SUCCEEDED, now()->subDays(5));
        $this->build($repository, Build::STATUS_SUCCEEDED, now()->subDays(4));
        $this->build($repository, Build::STATUS_SUCCEEDED, now()->subDays(3));
        $this->build($repository, Build::STATUS_FAILED, now()->subDays(2));
        $this->build($repository, Build::STATUS_CANCELED, $latestAt);
        $this->build($foreignRepository, Build::STATUS_SUCCEEDED, now(), 'Foreign private deployment');

        $this->actingAs($owner)->get(route('builds.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 7
                && $metrics['active'] === 2
                && $metrics['succeeded'] === 3
                && $metrics['failed'] === 1
                && $metrics['success_rate'] === 75
                && $metrics['latest_at']->timestamp === $latestAt->timestamp)
            ->assertSee('Deployment history')
            ->assertSee('Matching deployments')
            ->assertSee('Active deployments')
            ->assertSee('Observed success')
            ->assertSee('75%')
            ->assertSee('Latest matching deployment')
            ->assertSee('active and canceled runs excluded.')
            ->assertDontSee('Foreign private deployment');
    }

    public function test_metrics_apply_every_history_filter_including_latest_per_repository(): void
    {
        [$owner, $first] = $this->repository('Owner');
        $second = $owner->repositories()->create([
            'provider_id' => $first->provider_id,
            'website_id' => $first->website_id,
            'name' => 'Second repository',
            'url' => 'github.com/example/second.git',
            'branch' => 'main',
            'description' => 'Source',
        ]);
        $this->build($first, Build::STATUS_SUCCEEDED, '2026-08-20 10:00:00', 'Searchable older release');
        $matching = $this->build($first, Build::STATUS_SUCCEEDED, '2026-08-20 12:00:00', 'Searchable current release');
        $this->build($second, Build::STATUS_SUCCEEDED, '2026-08-20 13:00:00', 'Searchable other release');

        $this->actingAs($owner)->get(route('builds.index', [
            'repository_id' => $first->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger' => Build::TRIGGER_WEBHOOK,
            'search' => 'Searchable',
            'latest' => 1,
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
        ]))
            ->assertSuccessful()
            ->assertViewHas('builds', fn ($builds): bool => $builds->count() === 1
                && $builds->getCollection()->sole()->id === $matching->id)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['active'] === 0
                && $metrics['succeeded'] === 1
                && $metrics['failed'] === 0
                && $metrics['success_rate'] === 100
                && $metrics['latest_at']->timestamp === $matching->created_at->timestamp);
    }

    public function test_canceled_only_and_empty_views_do_not_invent_a_success_rate(): void
    {
        [$owner, $repository] = $this->repository('Owner');
        $this->build($repository, Build::STATUS_CANCELED, now()->subDay());

        $this->actingAs($owner)->get(route('builds.index', ['status' => Build::STATUS_CANCELED]))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['active'] === 0
                && $metrics['succeeded'] === 0
                && $metrics['failed'] === 0
                && $metrics['success_rate'] === null
                && $metrics['latest_at'] !== null)
            ->assertSee('Not available')
            ->assertSee('No matching success or failure outcome.');

        $this->actingAs($owner)->get(route('builds.index', ['status' => Build::STATUS_RUNNING]))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'success_rate' => null,
                'latest_at' => null,
            ])
            ->assertSee('No matching deployment recorded.')
            ->assertSee('No builds match these filters');
    }

    /** @return array{User, Repository} */
    private function repository(string $prefix): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => "{$prefix} provider",
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create(['name' => "{$prefix} server"]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => "{$prefix} website",
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => str($prefix)->slug().'.example.com',
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => "{$prefix} repository",
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Source',
        ]);

        return [$owner, $repository];
    }

    private function build(
        Repository $repository,
        string $status,
        mixed $createdAt,
        string $commitMessage = 'Deployment fixture',
    ): Build {
        return $repository->builds()->create([
            'status' => $status,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => $commitMessage,
            'created_at' => $createdAt,
        ]);
    }
}
