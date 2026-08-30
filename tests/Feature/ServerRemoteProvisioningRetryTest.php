<?php

namespace Tests\Feature;

use App\Jobs\Server\RetryRemoteServerProvisioningJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\ManagedSsh;
use App\Services\ProvisioningCallbackUrl;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServerRemoteProvisioningRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_resume_remote_provisioning_with_a_fresh_attempt(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();
        $oldToken = $server->provisioning_token;
        $oldStatusCallback = ProvisioningCallbackUrl::serverStatus($server);
        $oldFailureCallback = ProvisioningCallbackUrl::serverFailure($server);

        $this->actingAs($user)->get(route('servers.show', $server))
            ->assertSuccessful()
            ->assertSee('Resume provisioning');

        $response = $this->actingAs($user)->post(route('servers.provisioning.retry', $server));
        $response->assertRedirect()
            ->assertSessionHas('success', 'Remote server provisioning retry queued.')
            ->assertSessionHas('root_password');

        $server->refresh();
        $rootPassword = $server->password;
        $this->assertNotNull($rootPassword);
        $this->assertSame(Server::STATUS_QUEUED, $server->provisioning_status);
        $this->assertNotSame($oldToken, $server->provisioning_token);
        $this->assertNull($server->provisioning_error);
        $this->assertNull($server->provisioning_failure_phase);
        $this->assertNotSame($rootPassword, DB::table('servers')->find($server->id)->password);

        Queue::assertPushed(RetryRemoteServerProvisioningJob::class, function (RetryRemoteServerProvisioningJob $job) use ($server, $rootPassword): bool {
            $this->assertSame($server->id, $job->serverId);
            $this->assertSame($server->provisioning_token, $job->attemptToken);
            $this->assertStringNotContainsString($rootPassword, serialize($job));

            return true;
        });

        $this->post($oldStatusCallback, ['status' => 7])->assertNoContent();
        $this->post($oldFailureCallback, ['message' => 'Late remote failure'])->assertNoContent();
        $this->assertSame(Server::STATUS_QUEUED, $server->fresh()->provisioning_status);
    }

    public function test_resume_job_only_renders_unfinished_steps_and_clears_temporary_password(): void
    {
        [, $server] = $this->resources();
        $server->update([
            'provisioning_status' => Server::STATUS_QUEUED,
            'provisioning_failure_phase' => null,
            'password' => 'temporary-root-secret',
        ]);
        $script = null;
        $upload = Mockery::mock(Process::class);
        $upload->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $remote = Mockery::mock(Process::class);
        $remote->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $remote->shouldReceive('getOutput')->once()->andReturn("4321\n");
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('upload')
            ->once()
            ->withArgs(function (string $sourcePath) use (&$script): bool {
                $script = file_get_contents($sourcePath);

                return true;
            })
            ->andReturn($upload);
        $ssh->shouldReceive('execute')->once()->andReturn($remote);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->withArgs(fn (Server $value): bool => $value->is($server))->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        (new RetryRemoteServerProvisioningJob($server->id, $server->provisioning_token))->handle($runner);

        $server->refresh();
        $this->assertSame(Server::STATUS_PROVISIONING, $server->provisioning_status);
        $this->assertNull($server->password);
        $this->assertSame(4321, $server->provisioning_process_id);
        $this->assertMatchesRegularExpression('#^/tmp/server-'.$server->id.'-provisioning-[a-z0-9]{8}\.sh$#', $server->provisioning_process_path);
        $this->assertNotNull($script);
        $this->assertStringContainsString('provisionPing '.$server->id.' 3', $script);
        $this->assertStringNotContainsString('provisionPing '.$server->id.' 1', $script);
        $this->assertStringNotContainsString('provisionPing '.$server->id.' 2', $script);
        $this->assertStringContainsString('if [ ! -f "/home/$SERVER_NAME/.ssh/id_rsa" ]', $script);
        $this->assertSame(1, substr_count($script, 'provisionPing()'));

        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }

    public function test_duplicate_jobs_and_stale_attempts_do_not_launch_remote_work(): void
    {
        [, $server] = $this->resources();
        $server->update([
            'provisioning_status' => Server::STATUS_PROVISIONING,
            'provisioning_process_id' => 9876,
            'provisioning_process_path' => '/tmp/existing.sh',
        ]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');

        (new RetryRemoteServerProvisioningJob($server->id, $server->provisioning_token))->handle($runner);
        (new RetryRemoteServerProvisioningJob($server->id, 'stale-attempt'))->handle($runner);

        $this->assertSame(9876, $server->fresh()->provisioning_process_id);
    }

    public function test_retry_is_atomic_and_only_remote_failures_are_eligible(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();

        $this->actingAs($user)->post(route('servers.provisioning.retry', $server))->assertSessionHas('success');
        $this->actingAs($user)->post(route('servers.provisioning.retry', $server->fresh()))
            ->assertSessionHas('info', 'Remote server provisioning is not eligible for retry.');
        Queue::assertPushedTimes(RetryRemoteServerProvisioningJob::class, 1);

        foreach ([Server::FAILURE_CREATION, Server::FAILURE_INITIALIZATION] as $phase) {
            $server->update([
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_failure_phase' => $phase,
            ]);
            $this->actingAs($user)->get(route('servers.show', $server->fresh()))
                ->assertSuccessful()
                ->assertDontSee('Resume provisioning');
        }
    }

    public function test_retry_requires_ssh_access_and_owner_authorization(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();
        $server->update(['ssh_private_key' => null]);

        $this->actingAs($user)->post(route('servers.provisioning.retry', $server))
            ->assertSessionHasErrors(['retry' => 'The server must still be reachable by SSH before provisioning can be retried.']);
        $this->actingAs(User::factory()->create())->post(route('servers.provisioning.retry', $server))
            ->assertForbidden();

        $this->assertSame(Server::STATUS_FAILED, $server->fresh()->provisioning_status);
        Queue::assertNotPushed(RetryRemoteServerProvisioningJob::class);
    }

    public function test_terminal_callbacks_and_job_failure_clear_temporary_state(): void
    {
        [, $server] = $this->resources();
        $server->update([
            'provisioning_status' => Server::STATUS_PROVISIONING,
            'password' => 'temporary-root-secret',
            'provisioning_process_id' => 123,
            'provisioning_process_path' => '/tmp/retry.sh',
        ]);

        $this->post(ProvisioningCallbackUrl::serverFailure($server), ['message' => 'Package failed'])
            ->assertNoContent();
        $server->refresh();
        $this->assertSame(Server::STATUS_FAILED, $server->provisioning_status);
        $this->assertSame(Server::FAILURE_REMOTE, $server->provisioning_failure_phase);
        $this->assertNull($server->password);
        $this->assertNull($server->provisioning_process_id);
        $this->assertNull($server->provisioning_process_path);

        $server->update([
            'provisioning_status' => Server::STATUS_QUEUED,
            'password' => 'another-temporary-secret',
        ]);
        (new RetryRemoteServerProvisioningJob($server->id, $server->provisioning_token))
            ->failed(new RuntimeException('SSH unavailable'));
        $server->refresh();
        $this->assertSame(Server::STATUS_FAILED, $server->provisioning_status);
        $this->assertSame('SSH unavailable', $server->provisioning_error);
        $this->assertNull($server->password);
    }

    private function resources(): array
    {
        $user = User::factory()->create(['name' => 'Provisioning Owner']);
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'provider-secret',
            'description' => 'Cloud provider',
        ]);
        $server = $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => 'Cache Server',
            'type' => ServerTypeEnum::cache,
            'public_ip' => '192.0.2.10',
            'ssh_public_key' => 'ssh-ed25519 public-key',
            'ssh_private_key' => 'private-key',
            'setup_stage' => 2,
            'provisioning_status' => Server::STATUS_FAILED,
            'provisioning_failure_phase' => Server::FAILURE_REMOTE,
            'provisioning_error' => 'Configuration failed',
        ]);

        return [$user, $server];
    }
}
