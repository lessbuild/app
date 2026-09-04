<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryDeploymentInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_summarizes_outcomes_and_valid_recent_durations(): void
    {
        [$owner, $repository] = $this->repository('Owner');
        [, $foreignRepository] = $this->repository('Foreign');
        $this->timedBuild($repository, Build::STATUS_SUCCEEDED, 60, '2026-08-20 08:00:00');
        $this->timedBuild($repository, Build::STATUS_SUCCEEDED, 180, '2026-08-20 09:00:00');
        $this->timedBuild($repository, Build::STATUS_FAILED, 300, '2026-08-20 10:00:00');
        $repository->builds()->create([
            'status' => Build::STATUS_CANCELED,
            'finished_at' => '2026-08-20 11:01:00',
            'created_at' => '2026-08-20 11:00:00',
        ]);
        $repository->builds()->create([
            'status' => Build::STATUS_RUNNING,
            'started_at' => '2026-08-20 12:00:00',
            'created_at' => '2026-08-20 12:00:00',
        ]);
        $this->timedBuild($foreignRepository, Build::STATUS_FAILED, 3600, '2026-08-20 13:00:00');

        $this->actingAs($owner)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertViewHas('deploymentMetrics', [
                'total' => 5,
                'succeeded' => 2,
                'failed' => 1,
                'success_rate' => 67,
                'median_duration_seconds' => 180,
                'duration_sample_size' => 3,
            ])
            ->assertSee('Deployment insights')
            ->assertSee('Completed-run success rate')
            ->assertSee('67%')
            ->assertSee('Recent median duration')
            ->assertSee('3m')
            ->assertSee('3 timed deployments')
            ->assertSee(route('builds.index', ['repository_id' => $repository->id]))
            ->assertSee(route('builds.index', [
                'repository_id' => $repository->id,
                'status' => Build::STATUS_SUCCEEDED,
            ]))
            ->assertSee(route('builds.index', [
                'repository_id' => $repository->id,
                'status' => Build::STATUS_FAILED,
            ]))
            ->assertSee('View all deployments')
            ->assertDontSee(route('builds.index', ['repository_id' => $foreignRepository->id]));
    }

    public function test_empty_repository_has_explicit_unavailable_insights(): void
    {
        [$owner, $repository] = $this->repository('Empty');

        $this->actingAs($owner)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertViewHas('deploymentMetrics', [
                'total' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'success_rate' => null,
                'median_duration_seconds' => null,
                'duration_sample_size' => 0,
            ])
            ->assertSee('Not available')
            ->assertSee('Not recorded')
            ->assertSee('0 timed deployments');
    }

    public function test_median_duration_is_bounded_to_twenty_recent_timed_deployments(): void
    {
        [$owner, $repository] = $this->repository('Bounded');
        $this->timedBuild($repository, Build::STATUS_SUCCEEDED, 10_000, '2026-08-19 08:00:00');
        foreach (range(0, 19) as $offset) {
            $this->timedBuild(
                $repository,
                Build::STATUS_SUCCEEDED,
                60,
                CarbonImmutable::parse('2026-08-20 08:00:00')->addMinutes($offset)->toDateTimeString(),
            );
        }

        $this->actingAs($owner)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertViewHas('deploymentMetrics', fn (array $metrics): bool => $metrics['total'] === 21
                && $metrics['median_duration_seconds'] === 60
                && $metrics['duration_sample_size'] === 20)
            ->assertSee('1m')
            ->assertSee('20 timed deployments');
    }

    private function timedBuild(
        Repository $repository,
        string $status,
        int $duration,
        string $startedAt,
    ): Build {
        return $repository->builds()->create([
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => CarbonImmutable::parse($startedAt)->addSeconds($duration),
            'created_at' => $startedAt,
        ]);
    }

    /** @return array{User, Repository} */
    private function repository(string $prefix): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => "{$prefix} GitHub",
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => "{$prefix} server",
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => "{$prefix} website",
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => str($prefix)->lower().'.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => "{$prefix} repository",
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ])];
    }
}
