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

class DeploymentComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_compare_escaped_metadata_and_duration_improvement(): void
    {
        [$owner, $repository] = $this->repositories();
        $baseline = $repository->builds()->create([
            'status' => Build::STATUS_FAILED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'revision' => str_repeat('a', 40),
            'commit_message' => '<script>baseline()</script>',
            'operator_note' => 'Investigate incident INC-2048.',
            'failure_message' => '<strong>Dependency install failed.</strong>',
            'started_at' => '2026-08-20 08:00:00',
            'finished_at' => '2026-08-20 08:03:00',
            'created_at' => '2026-08-20 08:00:00',
        ]);
        $current = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_REDEPLOY,
            'revision' => str_repeat('b', 40),
            'commit_message' => 'Restore stable release',
            'operator_note' => '<em>Rollback approved.</em>',
            'started_at' => '2026-08-20 09:00:00',
            'finished_at' => '2026-08-20 09:01:00',
            'created_at' => '2026-08-20 09:00:00',
        ]);

        $response = $this->actingAs($owner)->get(route('builds.compare', [
            'build' => $current,
            'baseline' => $baseline,
        ]));

        $response
            ->assertSuccessful()
            ->assertSee('Compare deployments')
            ->assertSee('Baseline Build #'.$baseline->id)
            ->assertSee('Current Build #'.$current->id)
            ->assertSee(route('builds.show', $baseline))
            ->assertSee(route('builds.show', $current))
            ->assertSee(route('builds.compare', ['build' => $baseline, 'baseline' => $current]))
            ->assertSee('2m faster')
            ->assertSee('<script>baseline()</script>')
            ->assertDontSee('<script>baseline()</script>', false)
            ->assertSee('<strong>Dependency install failed.</strong>')
            ->assertDontSee('<strong>Dependency install failed.</strong>', false)
            ->assertSee('<em>Rollback approved.</em>')
            ->assertDontSee('<em>Rollback approved.</em>', false)
            ->assertSee(str_repeat('a', 12))
            ->assertSee(str_repeat('b', 12));

        $this->get(route('builds.show', $current))
            ->assertSuccessful()
            ->assertSee(route('builds.compare', ['build' => $current, 'baseline' => $baseline]))
            ->assertSee('Compare with previous');
    }

    public function test_comparison_reports_slower_equal_and_unavailable_durations_honestly(): void
    {
        [$owner, $repository] = $this->repositories();
        $baseline = $this->timedBuild($repository, 60, '2026-08-20 08:00:00');
        $slower = $this->timedBuild($repository, 180, '2026-08-20 09:00:00');
        $equal = $this->timedBuild($repository, 60, '2026-08-20 10:00:00');
        $unfinished = $repository->builds()->create([
            'status' => Build::STATUS_RUNNING,
            'started_at' => '2026-08-20 11:00:00',
            'created_at' => '2026-08-20 11:00:00',
        ]);

        $this->actingAs($owner)->get(route('builds.compare', ['build' => $slower, 'baseline' => $baseline]))
            ->assertSuccessful()
            ->assertSee('2m slower');
        $this->get(route('builds.compare', ['build' => $equal, 'baseline' => $baseline]))
            ->assertSuccessful()
            ->assertSee('No duration change');
        $this->get(route('builds.compare', ['build' => $unfinished, 'baseline' => $equal]))
            ->assertSuccessful()
            ->assertSee('Comparison unavailable');
    }

    public function test_comparison_requires_distinct_owned_builds_from_one_repository(): void
    {
        [$owner, $repository, $otherRepository] = $this->repositories();
        [$otherOwner, $foreignRepository] = $this->repositories();
        $build = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $otherRepositoryBuild = $otherRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $foreignBuild = $foreignRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $this->actingAs($owner)->get(route('builds.compare', ['build' => $build, 'baseline' => $build]))
            ->assertNotFound();
        $this->get(route('builds.compare', ['build' => $build, 'baseline' => $otherRepositoryBuild]))
            ->assertNotFound();
        $this->get(route('builds.compare', ['build' => $build, 'baseline' => $foreignBuild]))
            ->assertForbidden();
        $this->actingAs($otherOwner)->get(route('builds.compare', ['build' => $build, 'baseline' => $foreignBuild]))
            ->assertForbidden();
        auth()->logout();
        $this->get(route('builds.compare', ['build' => $build, 'baseline' => $otherRepositoryBuild]))
            ->assertRedirect(route('login'));
    }

    private function timedBuild(Repository $repository, int $seconds, string $startedAt): Build
    {
        return $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'started_at' => $startedAt,
            'finished_at' => CarbonImmutable::parse($startedAt)->addSeconds($seconds),
            'created_at' => $startedAt,
        ]);
    }

    /** @return array{User, Repository, Repository} */
    private function repositories(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [
            $owner,
            $owner->repositories()->create([
                'provider_id' => $provider->id,
                'website_id' => $website->id,
                'name' => 'Application repository',
                'url' => 'github.com/example/application.git',
                'branch' => 'main',
                'description' => 'Application source',
            ]),
            $owner->repositories()->create([
                'provider_id' => $provider->id,
                'website_id' => $website->id,
                'name' => 'Other repository',
                'url' => 'github.com/example/other.git',
                'branch' => 'main',
                'description' => 'Other source',
            ]),
        ];
    }
}
