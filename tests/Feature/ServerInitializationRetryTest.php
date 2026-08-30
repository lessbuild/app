<?php

namespace Tests\Feature;

use App\Actions\Server\UpdateServerIpAction;
use App\Jobs\Server\InitialiseServerJob;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\ProvisioningCallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $provisioningToken = $server->provisioning_token;
        $oldInitializationToken = $server->initialization_token;
        $oldJob = new InitialiseServerJob($server);

        $this->actingAs($user)->get(route('servers.show', $server))
            ->assertSuccessful()
            ->assertSee('Retry initialization');

        $this->actingAs($user)->post(route('servers.initialization.retry', $server))
            ->assertRedirect()
            ->assertSessionHas('success', 'Server initialization retry queued.');

        $server->refresh();
        $this->assertSame(Server::STATUS_QUEUED, $server->provisioning_status);
        $this->assertSame($provisioningToken, $server->provisioning_token);
        $this->assertNotSame($oldInitializationToken, $server->initialization_token);
        $this->assertNull($server->provisioning_error);
        $this->assertNull($server->provisioning_failure_phase);
        Queue::assertPushed(InitialiseServerJob::class, fn (InitialiseServerJob $job): bool => $job->server->is($server)
            && $job->attemptToken === $server->initialization_token);

        $updateIp = Mockery::mock(UpdateServerIpAction::class);
        $updateIp->shouldNotReceive('handle');
        $oldJob->handle($updateIp);
        $oldJob->failed(new RuntimeException('Late initialization failure'));
        $this->assertSame(Server::STATUS_QUEUED, $server->fresh()->provisioning_status);
    }

    public function test_initialization_retry_keeps_the_running_cloud_init_callbacks_valid(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();
        $statusCallback = ProvisioningCallbackUrl::serverStatus($server);
        $failureCallback = ProvisioningCallbackUrl::serverFailure($server);

        $this->actingAs($user)->post(route('servers.initialization.retry', $server))
            ->assertSessionHas('success');

        $this->post($statusCallback, ['status' => 1])->assertSuccessful();
        $this->assertSame(1, $server->fresh()->setup_stage);

        $this->post($failureCallback, ['message' => 'Cloud init package failure'])->assertNoContent();
        $server->refresh();
        $this->assertSame(Server::STATUS_FAILED, $server->provisioning_status);
        $this->assertSame(Server::FAILURE_REMOTE, $server->provisioning_failure_phase);
        $this->assertSame('Cloud init package failure', $server->provisioning_error);
        $this->assertNull($server->initialization_token);
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
        $this->assertNull($server->initialization_token);
    }

    public function test_successful_initialization_invalidates_late_job_failures(): void
    {
        [, $server] = $this->resources();
        $server->update([
            'public_ip' => '192.0.2.10',
            'provisioning_status' => Server::STATUS_QUEUED,
            'provisioning_failure_phase' => null,
        ]);
        $job = new InitialiseServerJob($server->fresh());
        $updateIp = Mockery::mock(UpdateServerIpAction::class);
        $updateIp->shouldNotReceive('handle');

        $job->handle($updateIp);
        $job->failed(new RuntimeException('Late worker failure'));

        $server->refresh();
        $this->assertSame(Server::STATUS_PROVISIONING, $server->provisioning_status);
        $this->assertNull($server->initialization_token);
        $this->assertNull($server->provisioning_error);
    }

    public function test_migration_preserves_already_queued_initialization_jobs(): void
    {
        [, $server] = $this->resources();
        $server->update(['provisioning_status' => Server::STATUS_QUEUED]);
        $provisioningToken = $server->provisioning_token;
        $migration = require database_path('migrations/2026_08_30_150000_add_server_initialization_attempts.php');

        $migration->down();
        $migration->up();

        $this->assertSame(
            $provisioningToken,
            DB::table('servers')->where('id', $server->id)->value('initialization_token'),
        );
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
