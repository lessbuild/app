<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RepositoryDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_deploy_creates_a_queued_build_and_dispatches_it(): void
    {
        Queue::fake();
        [$user, $repository] = $this->repository();

        $response = $this->actingAs($user)
            ->post(route('repositories.deploy', $repository))
            ->assertSessionHas('success', 'Deployment queued');

        $build = $repository->builds()->sole();
        $response->assertRedirect(route('builds.show', $build));
        $this->assertSame(Build::STATUS_QUEUED, $build->status);
        Queue::assertPushed(PublishRepositoryJob::class, fn ($job) => $job->build->is($build));
    }

    public function test_first_deployment_shows_actionable_preflight_and_launches_into_live_progress(): void
    {
        [$user, $repository] = $this->repository();

        $this->actingAs($user)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertSee('Review the launch checks')
            ->assertSee('Source')
            ->assertSee('Health verification')
            ->assertSee('Release recovery')
            ->assertSee('Push automation')
            ->assertSee('Launch first deployment')
            ->assertSee(route('websites.edit', $repository->website), false);

        $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $this->actingAs($user)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertDontSee('Review the launch checks');
    }

    public function test_a_repository_cannot_have_overlapping_deployments(): void
    {
        Queue::fake();
        [$user, $repository] = $this->repository();
        $repository->builds()->create(['status' => Build::STATUS_RUNNING]);

        $this->actingAs($user)
            ->post(route('repositories.deploy', $repository))
            ->assertSessionHas('info', 'A deployment is already in progress');

        $this->assertCount(1, $repository->builds);
        Queue::assertNotPushed(PublishRepositoryJob::class);
    }

    public function test_final_signed_callback_marks_the_current_build_as_succeeded(): void
    {
        Queue::fake();
        [, $repository] = $this->repository();
        $build = $repository->builds()->create([
            'status' => Build::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $finalStage = app(RepositoryDeploymentPlan::class)->finalStage();
        $this->post(URL::signedRoute('callbacks.build.status', $build), ['status' => $finalStage])
            ->assertSuccessful();

        $build->refresh();
        $this->assertSame(Build::STATUS_SUCCEEDED, $build->status);
        $this->assertSame($finalStage, $build->setup_stage);
        $this->assertNotNull($build->built_at);
        $this->assertNotNull($build->finished_at);
    }

    public function test_build_detail_uses_its_own_progress_and_explains_the_failed_step(): void
    {
        [$user, $repository] = $this->repository();
        $failed = $repository->builds()->create([
            'status' => Build::STATUS_FAILED,
            'setup_stage' => 4,
            'failure_message' => 'npm build exited with code 1',
            'finished_at' => now(),
        ]);
        $repository->update(['setup_stage' => app(RepositoryDeploymentPlan::class)->finalStage()]);
        $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED, 'setup_stage' => 15]);

        $this->actingAs($user)->get(route('builds.show', $failed))
            ->assertSuccessful()
            ->assertSee('Deployment progress')
            ->assertSee('Check dependencies and runtime')
            ->assertSee('Install Repository Dependencies')
            ->assertSee('Run custom build commands')
            ->assertSee('Inspect deployment log')
            ->assertSee('Not completed');
    }

    public function test_failed_build_offers_last_known_good_release_and_success_shows_health_actions(): void
    {
        [$user, $repository] = $this->repository();
        $repository->website->update(['health_check_enabled' => true, 'health_check_path' => '/up', 'health_status' => 'healthy']);
        $knownGood = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'release_name' => 'release-good',
            'release_path' => '/var/www/app/releases/release-good',
            'finished_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
        ]);
        $failed = $repository->builds()->create([
            'status' => Build::STATUS_FAILED,
            'failure_message' => 'Health check failed',
            'setup_stage' => 13,
            'finished_at' => now(),
        ]);

        $this->actingAs($user)->get(route('builds.show', $failed))
            ->assertSuccessful()
            ->assertSee('Restore the last known-good release')
            ->assertSee('Restore build #'.$knownGood->id)
            ->assertSee(route('builds.rollback', $knownGood), false);

        $this->actingAs($user)->get(route('builds.show', $knownGood))
            ->assertSuccessful()
            ->assertSee('Post-deployment verification')
            ->assertSee('Current monitor state: Healthy.')
            ->assertSee('Run health check now')
            ->assertSee('https://'.$repository->website->url, false);
    }

    public function test_job_failure_is_recorded_on_the_build(): void
    {
        Queue::fake();
        [, $repository] = $this->repository();
        $build = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);

        (new PublishRepositoryJob($build))->failed(new \RuntimeException('SSH connection failed'));

        $build->refresh();
        $this->assertSame(Build::STATUS_FAILED, $build->status);
        $this->assertSame('SSH connection failed', $build->failure_message);
        $this->assertNotNull($build->finished_at);
    }

    public function test_remote_script_failure_callback_records_the_exit_code(): void
    {
        Queue::fake();
        [, $repository] = $this->repository();
        $build = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);

        $this->post(URL::signedRoute('callbacks.build.failed', $build), [
            'exit_code' => 127,
            'message' => 'Remote deployment script failed',
        ])->assertSuccessful();

        $build->refresh();
        $this->assertSame(Build::STATUS_FAILED, $build->status);
        $this->assertSame('Remote deployment script failed (exit code 127)', $build->failure_message);
        $this->assertNotNull($build->finished_at);
    }

    public function test_repository_progress_lists_every_stage_and_stops_polling_when_finished(): void
    {
        [$user, $repository] = $this->repository();
        $repository->update(['setup_stage' => app(RepositoryDeploymentPlan::class)->finalStage()]);
        $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);

        $this->actingAs($user)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertSee('Symlink files')
            ->assertSee('Run artisan commands')
            ->assertSee('Verify deployment health')
            ->assertSee('Purge Old Releases')
            ->assertDontSee('wire:poll.5s', false);
    }

    private function repository(): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => 'github',
            'token' => 'secret',
            'description' => 'Git provider',
        ]);
        $server = $user->servers()->create([
            'name' => 'Production',
            'provider_id' => $provider->id,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.test',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/app.git',
            'description' => 'Repository',
        ]);

        return [$user, $repository];
    }
}
