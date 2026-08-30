<?php

namespace Tests\Feature;

use App\Jobs\Web\AddWebsiteJob;
use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\ProvisioningCallbackUrl;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebsiteRelocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_move_retains_source_until_target_provisioning_succeeds(): void
    {
        Queue::fake();
        [$user, $oldServer, $newServer, $website] = $this->resources();

        $this->actingAs($user)->patch(route('websites.update', $website), [
            ...$this->payload($newServer),
            'name' => 'Moved application',
        ])->assertRedirect(route('websites.show', $website));

        $website->refresh();
        $this->assertSame($newServer->id, $website->server_id);
        $this->assertSame($oldServer->id, $website->previous_server_id);
        $this->assertSame(Website::STATUS_QUEUED, $website->provisioning_status);
        Queue::assertPushed(AddWebsiteJob::class);
        Queue::assertNotPushed(CleanupWebsitePlacementJob::class);

        $this->actingAs($user)->patch(route('websites.update', $website), $this->payload($newServer))
            ->assertSessionHasErrors('server_id');
        Queue::assertPushedTimes(AddWebsiteJob::class, 1);

        $this->post(ProvisioningCallbackUrl::websiteStatus($website), ['status' => 3])
            ->assertSuccessful();

        $website->refresh();
        $this->assertSame(Website::STATUS_ACTIVE, $website->provisioning_status);
        $this->assertSame($oldServer->id, $website->previous_server_id);
        Queue::assertPushed(CleanupWebsitePlacementJob::class, fn (CleanupWebsitePlacementJob $job): bool => $job->websiteId === $website->id
            && $job->serverId === $oldServer->id
            && $job->deploymentSlug === $website->deployment_slug);
    }

    public function test_cleanup_removes_old_database_files_and_caddy_configuration(): void
    {
        [, $oldServer, , $website] = $this->resources(moved: true);
        $website->update(['placement_cleanup_error' => 'Earlier failure']);
        $command = null;
        $runner = $this->runner(successful: true, command: $command);

        (new CleanupWebsitePlacementJob(
            $website->id,
            $oldServer->id,
            $website->deployment_slug,
        ))->handle($runner);

        $website->refresh();
        $this->assertNull($website->previous_server_id);
        $this->assertNull($website->placement_cleanup_error);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `application`', $command);
        $this->assertStringContainsString('DROP USER IF EXISTS', $command);
        $this->assertStringContainsString('application', $command);
        $this->assertStringContainsString("'/etc/caddy/websites/application.conf'", $command);
        $this->assertStringContainsString("'/var/www/application'", $command);
        $this->assertStringContainsString('systemctl reload caddy', $command);
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($command);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }

    public function test_failed_target_provisioning_keeps_source_and_can_retry_same_target(): void
    {
        Queue::fake();
        [$user, $oldServer, $newServer, $website] = $this->resources();
        $this->actingAs($user)->patch(route('websites.update', $website), $this->payload($newServer));
        $website->refresh();

        $this->post(ProvisioningCallbackUrl::websiteFailure($website), [
            'exit_code' => 1,
            'message' => 'Target provisioning failed',
        ])->assertNoContent();

        $website->refresh();
        $this->assertSame(Website::STATUS_FAILED, $website->provisioning_status);
        $this->assertSame($oldServer->id, $website->previous_server_id);
        Queue::assertNotPushed(CleanupWebsitePlacementJob::class);
        $this->actingAs($user)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertSee('The source placement was retained');

        $this->actingAs($user)->patch(route('websites.update', $website), $this->payload($newServer))
            ->assertRedirect(route('websites.show', $website));
        $this->assertSame(Website::STATUS_QUEUED, $website->fresh()->provisioning_status);
        Queue::assertPushedTimes(AddWebsiteJob::class, 2);
    }

    public function test_stale_attempt_callback_cannot_complete_a_retried_move(): void
    {
        Queue::fake();
        [$user, , $newServer, $website] = $this->resources();
        $this->actingAs($user)->patch(route('websites.update', $website), $this->payload($newServer));
        $website->refresh();
        $firstToken = $website->provisioning_token;
        $staleCallback = ProvisioningCallbackUrl::websiteStatus($website);
        $staleJob = new AddWebsiteJob($website);

        $this->post(ProvisioningCallbackUrl::websiteFailure($website), [
            'message' => 'First attempt failed',
        ])->assertNoContent();
        $this->actingAs($user)->patch(route('websites.update', $website->fresh()), $this->payload($newServer));
        $website->refresh();

        $this->assertNotSame($firstToken, $website->provisioning_token);
        $staleJob->failed(new RuntimeException('Late worker failure'));
        $staleJob->handle();
        $this->post($staleCallback, ['status' => 3])->assertNoContent();
        $this->assertSame(Website::STATUS_QUEUED, $website->fresh()->provisioning_status);
        Queue::assertNotPushed(CleanupWebsitePlacementJob::class);

        $this->post(ProvisioningCallbackUrl::websiteStatus($website->fresh()), ['status' => 3])
            ->assertSuccessful();
        $this->assertSame(Website::STATUS_ACTIVE, $website->fresh()->provisioning_status);
        Queue::assertPushed(CleanupWebsitePlacementJob::class);
    }

    public function test_failed_cleanup_is_visible_and_can_be_retried(): void
    {
        [$user, $oldServer, , $website] = $this->resources(moved: true);
        $command = null;
        $job = new CleanupWebsitePlacementJob($website->id, $oldServer->id, $website->deployment_slug);

        try {
            $job->handle($this->runner(successful: false, command: $command));
            $this->fail('Expected placement cleanup to fail.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $website->refresh();
        $this->assertSame($oldServer->id, $website->previous_server_id);
        $this->assertStringContainsString('Permission denied', $website->placement_cleanup_error);
        $this->actingAs($user)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertSee('Previous server cleanup pending')
            ->assertSee('Retry cleanup');

        $this->actingAs(User::factory()->create())
            ->post(route('websites.placement.cleanup', $website))
            ->assertForbidden();

        Queue::fake();
        $this->actingAs($user)->post(route('websites.placement.cleanup', $website))
            ->assertSessionHas('success', 'Previous server cleanup queued.');
        $this->assertNull($website->fresh()->placement_cleanup_error);
        Queue::assertPushed(CleanupWebsitePlacementJob::class, fn (CleanupWebsitePlacementJob $queued): bool => $queued->serverId === $oldServer->id);
    }

    public function test_website_deletion_cleans_current_and_previous_placements(): void
    {
        Queue::fake();
        [$user, $oldServer, $newServer, $website] = $this->resources(moved: true);
        $this->actingAs($user)->delete(route('websites.destroy', $website));
        $serverIds = [];
        $command = null;
        $runner = $this->runner(
            successful: true,
            command: $command,
            times: 2,
            serverIds: $serverIds,
        );

        (new DeleteWebsiteFromCaddyJob($website->id))->handle($runner);

        $this->assertDatabaseMissing('websites', ['id' => $website->id]);
        $this->assertSame([$oldServer->id, $newServer->id], $serverIds);
    }

    public function test_source_cleanup_failure_does_not_remove_active_placement_during_deletion(): void
    {
        Queue::fake();
        [$user, $oldServer, , $website] = $this->resources(moved: true);
        $this->actingAs($user)->delete(route('websites.destroy', $website));
        $serverIds = [];
        $command = null;
        $job = new DeleteWebsiteFromCaddyJob($website->id);

        try {
            $job->handle($this->runner(
                successful: false,
                command: $command,
                serverIds: $serverIds,
            ));
            $this->fail('Expected source placement cleanup to fail.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $website->refresh();
        $this->assertNull($website->deleted_at);
        $this->assertSame(Website::STATUS_FAILED, $website->provisioning_status);
        $this->assertSame([$oldServer->id], $serverIds);
    }

    private function runner(
        bool $successful,
        ?string &$command,
        int $times = 1,
        ?array &$serverIds = null,
    ): Runner {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->times($times)->andReturn($successful);
        if (! $successful) {
            $process->shouldReceive('getErrorOutput')->once()->andReturn('Permission denied');
        }
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')
            ->times($times)
            ->withArgs(function (string $value) use (&$command): bool {
                $command = $value;

                return true;
            })
            ->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')
            ->times($times)
            ->withArgs(function (Server $server) use (&$serverIds): bool {
                if (is_array($serverIds)) {
                    $serverIds[] = $server->id;
                }

                return true;
            })
            ->andReturnSelf();
        $runner->shouldReceive('create')->times($times)->andReturn($ssh);

        return $runner;
    }

    private function resources(bool $moved = false): array
    {
        $user = User::factory()->create();
        $oldServer = $this->server($user, 'Old server', '192.0.2.10');
        $newServer = $this->server($user, 'New server', '192.0.2.20');
        $website = $user->websites()->create([
            ...$this->payload($moved ? $newServer : $oldServer),
            'deployment_slug' => 'application',
            'database_password' => 'database-secret',
            'provisioning_status' => Website::STATUS_ACTIVE,
            'previous_server_id' => $moved ? $oldServer->id : null,
        ]);

        return [$user, $oldServer, $newServer, $website];
    }

    private function server(User $user, string $name, string $ip): Server
    {
        return $user->servers()->create([
            'name' => $name,
            'type' => ServerTypeEnum::app,
            'public_ip' => $ip,
            'ssh_private_key' => 'private-key',
            'mysql_root_password' => 'mysql-root-secret',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
    }

    private function payload(Server $server): array
    {
        return [
            'server_id' => $server->id,
            'name' => 'Application',
            'url' => 'app.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
        ];
    }
}
