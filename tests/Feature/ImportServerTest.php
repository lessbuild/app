<?php

namespace Tests\Feature;

use App\Jobs\Server\RetryRemoteServerProvisioningJob;
use App\Models\Server;
use App\Models\ServerImportAssessment;
use App\Services\ServerDiscovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use phpseclib3\Crypt\RSA;
use Tests\TestCase;

class ImportServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_import_an_existing_server_for_remote_provisioning(): void
    {
        config()->set('billing.enforce_limits', false);
        Queue::fake();
        $owner = User::factory()->create();
        $privateKey = trim($this->privateKey());

        $discovery = Mockery::mock(ServerDiscovery::class);
        $discovery->shouldReceive('inspect')->once()->andReturn($this->report());
        $this->app->instance(ServerDiscovery::class, $discovery);
        $response = $this->actingAs($owner)->post(route('servers.import.store'), [
            'name' => 'Existing Production',
            'type' => 'web',
            'public_ip' => '1.1.1.1',
            'ssh_port' => 2222,
            'ssh_private_key' => $privateKey,
        ]);

        $assessment = ServerImportAssessment::query()->sole();
        $response->assertRedirect(route('servers.import.review', $assessment));
        $this->get(route('servers.import.review', $assessment))->assertOk()->assertSee('SHA256:test-host')->assertSee('No changes have been made');
        $response = $this->post(route('servers.import.confirm', $assessment), [
            'confirmation' => 'Existing Production', 'backup_confirmed' => '1', 'host_fingerprint_confirmed' => '1',
        ]);
        $server = Server::query()->sole();
        $response->assertRedirect(route('servers.show', $server));
        $this->assertSame($owner->current_organization_id, $server->organization_id);
        $this->assertNull($server->provider_id);
        $this->assertSame('Existing Production', str($server->name)->headline()->toString());
        $this->assertSame('1.1.1.1', $server->public_ip);
        $this->assertSame(2222, $server->ssh_port);
        $this->assertSame($privateKey, $server->ssh_private_key);
        $this->assertNotSame($privateKey, DB::table('servers')->value('ssh_private_key'));
        $this->assertSame(Server::STATUS_QUEUED, $server->provisioning_status);
        $this->assertSame('SHA256:test-host', $server->ssh_host_fingerprint);
        $this->assertNotNull($assessment->fresh()->consumed_at);
        $this->assertNotNull($server->password);
        $this->assertSame('queued', $server->logSnapshots()->sole()->status);
        Queue::assertPushed(RetryRemoteServerProvisioningJob::class, fn ($job): bool => $job->serverId === $server->id
            && $job->attemptToken === $server->provisioning_token);
    }

    public function test_import_rejects_invalid_access_details_without_storing_the_key(): void
    {
        config()->set('billing.enforce_limits', false);
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('servers.import.store'), [
            'name' => 'Unsafe import',
            'type' => 'web',
            'public_ip' => 'not-an-ip',
            'ssh_port' => 70000,
            'ssh_private_key' => 'not-a-private-key',
        ])->assertSessionHasErrors(['public_ip', 'ssh_port', 'ssh_private_key']);

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_import_blocks_internal_metadata_and_duplicate_server_addresses(): void
    {
        config()->set('billing.enforce_limits', false);
        $owner = User::factory()->create();
        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '224.0.0.1', '2001:db8::1'] as $address) {
            $this->actingAs($owner)->post(route('servers.import.store'), [...$this->payload(), 'public_ip' => $address])->assertSessionHasErrors('public_ip');
        }
        $owner->servers()->create(['name' => 'Existing', 'public_ip' => '1.1.1.1']);
        $this->actingAs($owner)->post(route('servers.import.store'), $this->payload())->assertSessionHasErrors('public_ip');
    }

    public function test_import_inspection_failure_does_not_store_credentials_or_create_a_server(): void
    {
        config()->set('billing.enforce_limits', false);
        $owner = User::factory()->create();
        $discovery = Mockery::mock(ServerDiscovery::class);
        $discovery->shouldReceive('inspect')->once()->andThrow(new \RuntimeException('Only Ubuntu servers are supported for safe import.'));
        $this->app->instance(ServerDiscovery::class, $discovery);

        $this->actingAs($owner)->post(route('servers.import.store'), $this->payload())
            ->assertSessionHasErrors(['connection' => 'Only Ubuntu servers are supported for safe import.']);
        $this->assertDatabaseCount('servers', 0);
        $this->assertDatabaseCount('server_import_assessments', 0);
    }

    public function test_import_requires_owner_bound_unexpired_assessment_and_explicit_confirmations(): void
    {
        config()->set('billing.enforce_limits', false);
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $discovery = Mockery::mock(ServerDiscovery::class);
        $discovery->shouldReceive('inspect')->once()->andReturn($this->report());
        $this->app->instance(ServerDiscovery::class, $discovery);
        $this->actingAs($owner)->post(route('servers.import.store'), $this->payload());
        $assessment = ServerImportAssessment::query()->sole();

        $this->actingAs($other)->get(route('servers.import.review', $assessment))->assertNotFound();
        $this->actingAs($owner)->post(route('servers.import.confirm', $assessment), [])->assertSessionHasErrors(['confirmation', 'backup_confirmed', 'host_fingerprint_confirmed']);
        $this->assertDatabaseCount('servers', 0);
        $assessment->update(['expires_at' => now()->subSecond()]);
        $this->get(route('servers.import.review', $assessment))->assertNotFound();
    }

    public function test_import_confirmation_is_single_use(): void
    {
        config()->set('billing.enforce_limits', false);
        Queue::fake();
        $owner = User::factory()->create();
        $discovery = Mockery::mock(ServerDiscovery::class);
        $discovery->shouldReceive('inspect')->once()->andReturn($this->report());
        $this->app->instance(ServerDiscovery::class, $discovery);
        $this->actingAs($owner)->post(route('servers.import.store'), $this->payload());
        $assessment = ServerImportAssessment::query()->sole();
        $confirmation = ['confirmation' => 'Existing Production', 'backup_confirmed' => '1', 'host_fingerprint_confirmed' => '1'];
        $this->post(route('servers.import.confirm', $assessment), $confirmation)->assertRedirect();
        $this->post(route('servers.import.confirm', $assessment), $confirmation)->assertNotFound();
        $this->assertDatabaseCount('servers', 1);
    }

    public function test_expired_and_consumed_assessments_are_pruned_with_encrypted_credentials(): void
    {
        $owner = User::factory()->create();
        foreach ([['expires_at' => now()->subMinute()], ['expires_at' => now()->addMinute(), 'consumed_at' => now()], ['expires_at' => now()->addMinute()]] as $index => $state) {
            ServerImportAssessment::query()->create([...$state, 'organization_id' => $owner->current_organization_id, 'user_id' => $owner->id,
                'token_hash' => hash('sha256', "token-{$index}"), 'configuration' => ['ssh_private_key' => "secret-{$index}"], 'report' => ['safe' => true]]);
        }
        $this->assertStringNotContainsString('secret-0', DB::table('server_import_assessments')->first()->configuration);
        $this->artisan('buildpusher:server-imports:prune')->assertSuccessful();
        $this->assertDatabaseCount('server_import_assessments', 1);
    }

    private function payload(): array
    {
        return ['name' => 'Existing Production', 'type' => 'web', 'public_ip' => '1.1.1.1', 'ssh_port' => 2222,
            'ssh_private_key' => $this->privateKey()];
    }

    private function report(): array
    {
        return ['known_host' => '[1.1.1.1]:2222 ssh-ed25519 AAAATEST', 'fingerprint' => 'SHA256:test-host', 'algorithm' => 'ssh-ed25519',
            'hostname' => 'production-1', 'os_id' => 'ubuntu', 'os_version' => '24.04', 'architecture' => 'x86_64', 'memory_mb' => '4096',
            'disk_free_mb' => '50000', 'services' => ['nginx'], 'warnings' => ['Existing services may be reconfigured or restarted during provisioning.'], 'inspected_at' => now()->toIso8601String()];
    }

    private function privateKey(): string
    {
        static $key;
        return $key ??= RSA::createKey(1024)->toString('OpenSSH');
    }
}
