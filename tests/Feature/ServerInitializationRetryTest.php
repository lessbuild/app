<?php

namespace Tests\Feature;

use App\Actions\Server\UpdateServerIpAction;
use App\Jobs\Server\InitialiseServerJob;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\ProvisioningCallbackUrl;
use App\Services\ServerProvisioningPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ServerInitializationRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_retry_failed_initialization_with_a_fresh_attempt(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();
        $oldToken = $server->provisioning_token;
        $oldStatusCallback = ProvisioningCallbackUrl::serverStatus($server);
        $oldFailureCallback = ProvisioningCallbackUrl::serverFailure($server);
        $oldJob = new InitialiseServerJob($server);

        $this->actingAs($user)->get(route('servers.show', $server))
            ->assertSuccessful()
            ->assertSee('Retry initialization');

        $this->actingAs($user)->post(route('servers.initialization.retry', $server))
            ->assertRedirect()
            ->assertSessionHas('success', 'Server initialization retry queued.');

        $server->refresh();
        $this->assertSame(Server::STATUS_QUEUED, $server->provisioning_status);
        $this->assertNotSame($oldToken, $server->provisioning_token);
        $this->assertNull($server->provisioning_error);
        $this->assertNull($server->provisioning_failure_phase);
        Queue::assertPushed(InitialiseServerJob::class, fn (InitialiseServerJob $job): bool => $job->server->is($server)
            && $job->attemptToken === $server->provisioning_token);

        $updateIp = Mockery::mock(UpdateServerIpAction::class);
        $updateIp->shouldNotReceive('handle');
        $oldJob->handle($updateIp);
        $oldJob->failed(new RuntimeException('Late initialization failure'));
        $this->post($oldStatusCallback, ['status' => app(ServerProvisioningPlan::class)->finalStage($server)])
            ->assertNoContent();
        $this->post($oldFailureCallback, ['message' => 'Late remote failure'])
            ->assertNoContent();
        $this->assertSame(Server::STATUS_QUEUED, $server->fresh()->provisioning_status);
    }

    public function test_retry_is_atomic_and_does_not_queue_duplicates(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();

        $this->actingAs($user)->post(route('servers.initialization.retry', $server))
            ->assertSessionHas('success');
        $this->actingAs($user)->post(route('servers.initialization.retry', $server->fresh()))
            ->assertSessionHas('info', 'Server initialization is not eligible for retry.');

        Queue::assertPushedTimes(InitialiseServerJob::class, 1);
    }

    public function test_remote_and_creation_failures_are_not_retried_as_initialization(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();

        foreach ([Server::FAILURE_REMOTE, Server::FAILURE_CREATION] as $phase) {
            $server->update([
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_failure_phase' => $phase,
            ]);

            $this->actingAs($user)->get(route('servers.show', $server->fresh()))
                ->assertSuccessful()
                ->assertDontSee('Retry initialization');
            $this->actingAs($user)->post(route('servers.initialization.retry', $server->fresh()))
                ->assertSessionHas('info', 'Server initialization is not eligible for retry.');
        }

        Queue::assertNotPushed(InitialiseServerJob::class);
    }

    public function test_retry_requires_the_original_cloud_resource_and_provider(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();
        $server->update(['identifier' => null]);

        $this->actingAs($user)->post(route('servers.initialization.retry', $server))
            ->assertSessionHasErrors([
                'retry' => 'The cloud server and its provider must still be available before initialization can be retried.',
            ]);

        $this->assertSame(Server::STATUS_FAILED, $server->fresh()->provisioning_status);
        Queue::assertNotPushed(InitialiseServerJob::class);
    }

    public function test_other_users_cannot_retry_server_initialization(): void
    {
        Queue::fake();
        [, $server] = $this->resources();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('servers.initialization.retry', $server))
            ->assertForbidden();

        $this->assertSame(Server::STATUS_FAILED, $server->fresh()->provisioning_status);
        Queue::assertNotPushed(InitialiseServerJob::class);
    }

    public function test_job_failure_records_a_retryable_initialization_phase(): void
    {
        [, $server] = $this->resources();
        $server->update([
            'provisioning_status' => Server::STATUS_WAITING_FOR_IP,
            'provisioning_failure_phase' => null,
        ]);

        (new InitialiseServerJob($server->fresh()))->failed(new RuntimeException('Address unavailable'));

        $server->refresh();
        $this->assertSame(Server::STATUS_FAILED, $server->provisioning_status);
        $this->assertSame(Server::FAILURE_INITIALIZATION, $server->provisioning_failure_phase);
        $this->assertSame('Address unavailable', $server->provisioning_error);
    }

    private function resources(): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'provider-secret',
            'description' => 'Cloud provider',
        ]);
        $server = $user->servers()->create([
            'provider_id' => $provider->id,
            'identifier' => 12345,
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_FAILED,
            'provisioning_failure_phase' => Server::FAILURE_INITIALIZATION,
            'provisioning_error' => 'The public address is not ready.',
        ]);

        return [$user, $server];
    }
}
