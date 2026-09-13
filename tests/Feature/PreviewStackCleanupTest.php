<?php

namespace Tests\Feature;

use App\Actions\Project\CleanupPreviewStackAction;
use App\Actions\Project\QueuePreviewStackCleanupAction;
use App\Jobs\Project\CleanupPreviewStackJob;
use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\PreviewDeployment;
use App\Models\PreviewStackCleanup;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\PreviewStackCleanupScript;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process as RemoteProcess;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class PreviewStackCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_captures_only_explicitly_owned_children_without_secrets(): void
    {
        Queue::fake();
        [$owner, $project, $preview, $environment] = $this->preview();
        $environment->processes()->create([
            'name' => 'manual', 'type' => 'worker', 'command' => 'php artisan horizon',
            'replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5,
            'is_enabled' => true, 'is_preview_owned' => false,
        ]);
        $environment->resources()->create([
            'name' => 'shared-database', 'type' => 'postgresql', 'is_managed' => true,
            'is_preview_owned' => false,
            'configuration' => ['variables' => [
                'DB_DATABASE' => 'shared_database', 'DB_USERNAME' => 'shared_database',
                'DB_PASSWORD' => 'shared-database-secret',
            ]],
        ]);

        $cleanup = app(QueuePreviewStackCleanupAction::class)->handle($preview);

        $this->assertNotNull($cleanup);
        $this->assertSame($preview->id, $cleanup->preview_deployment_id);
        $this->assertSame($environment->id, $cleanup->environment_id);
        $this->assertSame($preview->website->deployment_slug, $cleanup->deployment_slug);
        $this->assertSame([['name' => 'queue']], $cleanup->process_manifest);
        $this->assertCount(2, $cleanup->resource_manifest);
        $this->assertStringNotContainsString('database-secret', json_encode($cleanup->resource_manifest, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('shared-database-secret', json_encode($cleanup->resource_manifest, JSON_THROW_ON_ERROR));
        $this->assertSame(PreviewStackCleanup::STATUS_QUEUED, $cleanup->status);
        Queue::assertPushed(CleanupPreviewStackJob::class, fn (CleanupPreviewStackJob $job): bool => $job->cleanupId === $cleanup->id);
        $this->assertSame($owner->current_organization_id, $project->organization_id);
    }

    public function test_cleanup_script_removes_only_captured_processes_and_managed_resources(): void
    {
        Queue::fake();
        [, , $preview] = $this->preview();
        $cleanup = app(QueuePreviewStackCleanupAction::class)->handle($preview);
        $this->assertNotNull($cleanup);
        $command = null;

        (new CleanupPreviewStackJob($cleanup->id))->handle(
            app(CleanupPreviewStackAction::class),
            $this->runner(true, $command),
            app(PreviewStackCleanupScript::class),
        );

        $cleanup->refresh();
        $this->assertSame(PreviewStackCleanup::STATUS_SUCCEEDED, $cleanup->status);
        $this->assertSame(1, $cleanup->attempts);
        $this->assertStringContainsString('buildpusher-'.$cleanup->deployment_slug.'-queue-1.service', $command);
        $this->assertStringContainsString('buildpusher-'.$cleanup->deployment_slug.'-queue-20.service', $command);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS "'.$this->databaseIdentifier($preview).'"', $command);
        $this->assertStringContainsString('DROP ROLE IF EXISTS "'.$this->databaseIdentifier($preview).'"', $command);
        $this->assertStringContainsString("docker container inspect 'buildpusher-valkey-{$preview->environment_id}-cache'", $command);
        $this->assertStringContainsString("docker volume inspect 'buildpusher-valkey-{$preview->environment_id}-cache-data'", $command);
        $this->assertStringNotContainsString('database-secret', $command);
        $this->assertStringNotContainsString('shared', $command);
        $this->assertStringNotContainsString('manual', $command);

        $syntax = new SymfonyProcess(['bash', '-n']);
        $syntax->setInput($command);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }

    public function test_failed_cleanup_is_visible_and_a_later_attempt_can_retry_idempotently(): void
    {
        Queue::fake();
        [, , $preview] = $this->preview();
        $cleanup = app(QueuePreviewStackCleanupAction::class)->handle($preview);
        $this->assertNotNull($cleanup);
        $failedCommand = null;

        try {
            (new CleanupPreviewStackJob($cleanup->id))->handle(
                app(CleanupPreviewStackAction::class),
                $this->runner(false, $failedCommand),
                app(PreviewStackCleanupScript::class),
            );
            $this->fail('Expected remote preview cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to remove the preview stack from its server.', $exception->getMessage());
        }

        $cleanup->refresh();
        $this->assertSame(PreviewStackCleanup::STATUS_FAILED, $cleanup->status);
        $this->assertSame(1, $cleanup->attempts);
        $this->assertSame('Unable to remove the preview stack from its server.', $cleanup->error);

        (new CleanupPreviewStackJob($cleanup->id))->handle(
            app(CleanupPreviewStackAction::class),
            $this->runner(true, $command),
            app(PreviewStackCleanupScript::class),
        );

        $this->assertSame(PreviewStackCleanup::STATUS_SUCCEEDED, $cleanup->fresh()->status);
        $this->assertSame(2, $cleanup->fresh()->attempts);
    }

    public function test_cleanup_for_an_older_environment_uses_its_captured_server_and_slug_after_reopen(): void
    {
        Queue::fake();
        [, $project, $preview, $oldEnvironment, $oldServer] = $this->preview('preview-old');
        $cleanup = app(QueuePreviewStackCleanupAction::class)->handle($preview);
        $this->assertNotNull($cleanup);

        $newServer = $this->server($preview->website->user, 'New preview server', '192.0.2.21');
        $newWebsite = $preview->website->user->websites()->create([
            'server_id' => $newServer->id, 'deployment_slug' => 'preview-new', 'name' => 'Preview new',
            'description' => 'Preview', 'environment' => '', 'url' => 'preview-new.example.com',
            'database_password' => 'new-database-secret', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $newEnvironment = $project->environments()->create([
            'server_id' => $newServer->id, 'website_id' => $newWebsite->id, 'name' => 'PR 17 new',
            'slug' => 'pr-17-new', 'type' => 'preview', 'branch' => 'feature/new',
        ]);
        $preview->update([
            'environment_id' => $newEnvironment->id,
            'website_id' => $newWebsite->id,
            'status' => PreviewDeployment::STATUS_PROVISIONING,
        ]);
        $command = null;

        (new CleanupPreviewStackJob($cleanup->id))->handle(
            app(CleanupPreviewStackAction::class),
            $this->runner(true, $command, $capturedServerId),
            app(PreviewStackCleanupScript::class),
        );

        $this->assertSame($oldServer->id, $capturedServerId);
        $this->assertStringContainsString('buildpusher-preview-old-', $command);
        $this->assertStringNotContainsString('buildpusher-preview-new-', $command);
        $this->assertSame(PreviewStackCleanup::STATUS_SUCCEEDED, $cleanup->fresh()->status);
        $this->assertSame($oldEnvironment->id, $cleanup->environment_id);
    }

    public function test_manager_can_retry_a_failed_cleanup_from_the_project_page(): void
    {
        Queue::fake();
        [$owner, $project, $preview] = $this->preview();
        $cleanup = app(QueuePreviewStackCleanupAction::class)->handle($preview);
        $this->assertNotNull($cleanup);
        $cleanup->update(['status' => PreviewStackCleanup::STATUS_FAILED, 'error' => 'Cleanup failed']);
        Queue::fake();

        $this->actingAs($owner)->post(route('projects.previews.cleanup.retry', [$project, $preview]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Preview stack cleanup queued.');

        $this->assertSame(PreviewStackCleanup::STATUS_QUEUED, $cleanup->fresh()->status);
        Queue::assertPushed(CleanupPreviewStackJob::class, fn (CleanupPreviewStackJob $job): bool => $job->cleanupId === $cleanup->id);
    }

    public function test_an_outsider_cannot_retry_cleanup_or_queue_a_job(): void
    {
        Queue::fake();
        [$owner, $project, $preview] = $this->preview();
        $cleanup = app(QueuePreviewStackCleanupAction::class)->handle($preview);
        $this->assertNotNull($cleanup);
        $cleanup->update(['status' => PreviewStackCleanup::STATUS_FAILED, 'error' => 'Cleanup failed']);
        Queue::fake();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post(route('projects.previews.cleanup.retry', [$project, $preview]))
            ->assertForbidden();

        $this->assertSame(PreviewStackCleanup::STATUS_FAILED, $cleanup->fresh()->status);
        Queue::assertNothingPushed();
        $this->assertTrue($owner->is($project->organization->owner));
    }

    /** @return array{User, Project, PreviewDeployment, Environment, Server} */
    private function preview(string $slug = 'preview-app'): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub', 'provider' => Provider::TYPE_GITHUB, 'token' => 'token', 'description' => 'Source',
        ]);
        $server = $this->server($owner, 'Preview server', '192.0.2.20');
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'deployment_slug' => $slug, 'name' => 'Preview',
            'description' => 'Preview', 'environment' => '', 'url' => $slug.'.example.com',
            'database_password' => 'database-secret', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $source = $owner->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Source',
            'url' => 'github.com/example/source.git', 'branch' => 'main', 'description' => 'Source',
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id, 'name' => 'Preview', 'slug' => 'preview', 'preset' => 'laravel',
        ]);
        $environment = $project->environments()->create([
            'server_id' => $server->id, 'website_id' => $website->id, 'name' => 'PR 17',
            'slug' => 'pr-17', 'type' => 'preview', 'branch' => 'feature/preview',
        ]);
        $environment->processes()->create([
            'name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work', 'replicas' => 1,
            'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true,
            'is_preview_owned' => true,
        ]);
        $environment->resources()->createMany([
            [
                'name' => 'database', 'type' => 'postgresql', 'is_managed' => true,
                'is_preview_owned' => true, 'status' => EnvironmentResource::STATUS_READY,
                'configuration' => ['variables' => [
                    'DB_DATABASE' => $website->databaseIdentifier(), 'DB_USERNAME' => $website->databaseIdentifier(),
                    'DB_PASSWORD' => 'database-secret',
                ]],
            ],
            [
                'name' => 'cache', 'type' => 'valkey', 'is_managed' => true,
                'is_preview_owned' => true, 'status' => EnvironmentResource::STATUS_READY,
                'configuration' => ['container_name' => 'buildpusher-valkey-'.$environment->id.'-cache'],
            ],
        ]);
        $preview = $project->previews()->create([
            'source_repository_id' => $source->id, 'environment_id' => $environment->id,
            'website_id' => $website->id, 'repository_id' => $source->id, 'pull_request_number' => 17,
            'title' => 'Preview', 'source_branch' => 'feature/preview', 'revision' => str_repeat('a', 40),
            'status' => PreviewDeployment::STATUS_CLOSED, 'url' => $website->url,
            'last_activity_at' => now(), 'closed_at' => now(),
        ]);

        return [$owner, $project, $preview, $environment, $server];
    }

    private function server(User $user, string $name, string $ip): Server
    {
        return $user->servers()->create([
            'name' => $name, 'public_ip' => $ip, 'ssh_private_key' => 'private-key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
    }

    private function databaseIdentifier(PreviewDeployment $preview): string
    {
        return $preview->website->databaseIdentifier();
    }

    private function runner(bool $successful, ?string &$command, ?int &$serverId = null): Runner
    {
        $process = Mockery::mock(RemoteProcess::class);
        $process->shouldReceive('isSuccessful')->once()->andReturn($successful);
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')
            ->once()
            ->withArgs(function (string $value) use (&$command): bool {
                $command = $value;

                return true;
            })
            ->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')
            ->once()
            ->withArgs(function (Server $server) use (&$serverId): bool {
                $serverId = $server->id;

                return true;
            })
            ->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }
}
