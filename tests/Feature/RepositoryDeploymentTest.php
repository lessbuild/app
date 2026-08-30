<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
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

        $this->actingAs($user)
            ->post(route('repositories.deploy', $repository))
            ->assertSessionHas('success', 'Deployment queued');

        $build = $repository->builds()->sole();
        $this->assertSame(Build::STATUS_QUEUED, $build->status);
        Queue::assertPushed(PublishRepositoryJob::class, fn ($job) => $job->build->is($build));
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

        $this->post(URL::signedRoute('callbacks.build.status', $build), ['status' => 7])
            ->assertSuccessful();

        $build->refresh();
        $this->assertSame(Build::STATUS_SUCCEEDED, $build->status);
        $this->assertNotNull($build->built_at);
        $this->assertNotNull($build->finished_at);
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
        $repository->update(['setup_stage' => 7]);
        $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);

        $this->actingAs($user)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertSee('Symlink files')
            ->assertSee('Run artisan commands')
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
