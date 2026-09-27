<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Enums\ServerType;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServerLifecycleTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Provider $provider;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->provider = Provider::factory()->create(['account_id' => $this->project->account_id, 'name' => 'Main cloud']);
        $this->base = "/projects/{$this->project->id}/infrastructure/servers";
        $this->onTier($this->project, 'deploy', 'pro');
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\RequirePassword::class);
    }

    public function test_creating_a_server_registers_a_key_and_boots_it_with_the_provisioning_script(): void
    {
        $this->actingAs($this->owner)->post($this->base, $this->server(['type' => 'database']))->assertRedirect()->assertSessionHas('secrets');

        $server = Server::query()->sole();
        $this->assertSame(ServerType::Database, $server->type);
        $this->assertSame('db-1', $server->name);
        $this->assertSame('cloud-7', $server->identifier);
        $this->assertSame('key-1', $server->ssh_fingerprint);
        $this->assertNotNull($server->mysql_root_password);
        // The job ran on the sync queue: it found the IP, pinned the host key and handed over to the script.
        $this->assertSame(Server::STATUS_PROVISIONING, $server->provisioning_status);
        $this->assertSame('203.0.113.50', $server->public_ip);
        $this->assertSame('SHA256:fakehost', $server->ssh_host_fingerprint);
        $this->assertNull($server->initialization_token);

        $request = $this->cloud->created[0];
        $this->assertSame(['key-1'], $request['ssh_keys']);
        $this->assertStringContainsString("/servers/{$server->id}/provisioning/callback/status", (string) $request['user_data']);
        $this->assertStringContainsString('mysql', (string) $request['user_data']);
        $this->assertStringNotContainsString('caddy', strtolower((string) $request['user_data']));
        $this->assertSame(AuditAction::ServerCreated, AuditEntry::query()->where('account_id', $this->project->account_id)->sole()->action);
        $this->actingAs($this->owner)->get("{$this->base}/{$server->id}")->assertOk()->assertSee('Provisioning')->assertSee('203.0.113.50');
    }

    public function test_callbacks_move_a_server_through_its_stages_and_ignore_stale_attempts(): void
    {
        $server = Server::factory()->provisioning()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'type' => ServerType::Cache]);
        $status = $this->callbackUrl($server, 'status');

        $this->post($status, ['status' => 3])->assertNoContent();
        $this->assertSame(3, $this->reload($server)->setup_stage);
        $this->post($this->callbackUrl($server, 'log'), ['log' => "Installing redis\n"])->assertNoContent();
        $this->assertSame("Installing redis\n", $server->logSnapshots()->sole()->log);
        $this->post(str_replace('status', 'failed', $status), ['message' => 'x'])->assertForbidden();
        $this->post("/servers/{$server->id}/provisioning/callback/status", ['status' => 1])->assertForbidden();

        $final = app(\App\Services\Infrastructure\ServerProvisioningPlan::class)->finalStage($server);
        $this->post($status, ['status' => $final])->assertNoContent();
        $this->assertSame(Server::STATUS_ACTIVE, $this->reload($server)->provisioning_status);
        $this->assertNotNull($this->reload($server)->provisioned_at);

        $this->post($this->callbackUrl($server, 'failed'), ['message' => 'Too late'])->assertNoContent();
        $this->assertSame(Server::STATUS_ACTIVE, $this->reload($server)->provisioning_status);
        $this->reload($server)->forceFill(['provisioning_status' => Server::STATUS_PROVISIONING, 'provisioning_token' => '00000000-0000-0000-0000-000000000001'])->save();
        $this->post($status, ['status' => 1])->assertNoContent();
        $this->assertSame(Server::STATUS_PROVISIONING, $this->reload($server)->provisioning_status);
    }

    public function test_a_remote_failure_can_be_retried_over_ssh_from_the_failed_stage(): void
    {
        $server = Server::factory()->provisioning()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'setup_stage' => 5]);
        $this->post($this->callbackUrl($server, 'failed'), ['message' => 'apt failed', 'exit_code' => 100])->assertNoContent();
        $this->assertSame(Server::FAILURE_REMOTE, $this->reload($server)->provisioning_failure_phase);
        $this->assertSame('apt failed (exit code 100)', $this->reload($server)->provisioning_error);

        $this->actingAs($this->owner)->post("{$this->base}/{$server->id}/provisioning/retry")->assertRedirect()->assertSessionMissing('secrets');

        $server->refresh();
        $this->assertSame(Server::STATUS_PROVISIONING, $server->provisioning_status);
        $this->assertSame(4242, $server->provisioning_process_id);
        $this->assertCount(1, $this->scripts->started);
        $this->assertStringContainsString("provisionPing {$server->id} 6", $this->scripts->started[0]['script']);
        $this->assertStringNotContainsString("provisionPing {$server->id} 5\n", $this->scripts->started[0]['script']);
        $this->actingAs($this->owner)->post("{$this->base}/{$server->id}/provisioning/retry")->assertSessionHas('status', 'This server isn’t waiting for a retry.');
    }

    public function test_failed_creation_cleans_up_and_failed_initialisation_can_be_retried(): void
    {
        $this->cloud->failCreate = true;
        $this->actingAs($this->owner)->post($this->base, $this->server())->assertSessionHas('error');
        $failed = Server::query()->sole();
        $this->assertSame(Server::FAILURE_CREATION, $failed->provisioning_failure_phase);
        $this->assertSame(['key-1'], $this->cloud->deletedKeys);
        $this->assertNull($failed->ssh_fingerprint);

        $this->cloud->failCreate = false;
        $this->cloud->publicIp = null;
        $server = Server::factory()->provisioning(Server::STATUS_QUEUED)->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'public_ip' => null]);
        try {
            (new \App\Jobs\Infrastructure\InitialiseServer($server->id, (string) $server->initialization_token))->handle(app(\App\Services\Infrastructure\ServerProviderResolver::class), app(\App\Services\Infrastructure\SshHostIdentity::class));
            $this->fail('Should wait for the IP.');
        } catch (RuntimeException) {
            (new \App\Jobs\Infrastructure\InitialiseServer($server->id, (string) $server->initialization_token))->failed(new RuntimeException('No IP after ten tries.'));
        }
        $this->assertSame(Server::FAILURE_INITIALIZATION, $this->reload($server)->provisioning_failure_phase);

        $this->cloud->publicIp = '198.51.100.7';
        $this->actingAs($this->owner)->post("{$this->base}/{$server->id}/initialization/retry")->assertRedirect();
        $this->assertSame('198.51.100.7', $this->reload($server)->public_ip);
        $this->assertSame(Server::STATUS_PROVISIONING, $this->reload($server)->provisioning_status);
    }

    public function test_deleting_a_server_removes_it_at_the_provider_first(): void
    {
        $server = Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'identifier' => 'cloud-9', 'ssh_fingerprint' => 'key-9']);
        $this->cloud->failDelete = true;
        $this->actingAs($this->owner)->delete("{$this->base}/{$server->id}")->assertSessionHasErrors(['server' => 'Fake Cloud couldn’t delete the server.']);
        $this->assertModelExists($server);

        $this->cloud->failDelete = false;
        $this->actingAs($this->owner)->delete("{$this->base}/{$server->id}")->assertRedirect($this->base);
        $this->assertModelMissing($server);
        $this->assertContains('cloud-9', $this->cloud->deletedServers);
        $this->assertContains('key-9', $this->cloud->deletedKeys);
    }

    public function test_the_create_page_reads_the_catalog_and_the_plan_limits_servers(): void
    {
        $this->app->forgetInstance(\App\Services\Infrastructure\ServerProviderResolver::class);
        $this->app->offsetUnset(\App\Services\Infrastructure\ServerProviderResolver::class);
        Http::fake([
            'https://api.digitalocean.com/v2/regions*' => Http::response(['regions' => [['slug' => 'fra1', 'name' => 'Frankfurt 1', 'available' => true]]]),
            'https://api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [['slug' => 's-1vcpu-1gb', 'description' => 'Basic', 'memory' => 1024, 'vcpus' => 1, 'price_monthly' => 6]]]),
            'https://api.digitalocean.com/v2/images*' => Http::response(['images' => [['slug' => 'ubuntu-24-04-x64', 'distribution' => 'Ubuntu', 'name' => '24.04 (LTS) x64'], ['slug' => 'debian-12', 'distribution' => 'Debian', 'name' => '12']]]),
        ]);
        $this->actingAs($this->owner)->get("{$this->base}/create")->assertOk()
            ->assertSee('Frankfurt 1')->assertSee('Basic · 1 GB RAM · 1 vCPU · $6/month')->assertSee('Ubuntu 24.04 (LTS) x64')->assertDontSee('Debian');

        $this->onTier($this->project, 'deploy', 'free');
        Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id]);
        $this->actingAs($this->owner)->post($this->base, $this->server())->assertSessionHasErrors('plan');
        $this->actingAs($this->owner)->get($this->base)->assertSee('1 of 1 servers on your plan');
    }

    public function test_viewers_see_servers_but_cant_manage_them(): void
    {
        $server = Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'display_name' => 'Primary web']);
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);

        $this->actingAs($viewer)->get($this->base)->assertOk()->assertSee('Primary web')->assertDontSee('Create a server');
        $this->actingAs($viewer)->get("{$this->base}/{$server->id}")->assertOk()->assertDontSee('Delete server');
        $this->actingAs($viewer)->get("{$this->base}/create")->assertForbidden();
        $this->actingAs($viewer)->post($this->base, $this->server())->assertForbidden();
        $this->actingAs($viewer)->put("{$this->base}/{$server->id}", ['display_name' => 'Mine'])->assertForbidden();
        $this->actingAs($viewer)->delete("{$this->base}/{$server->id}")->assertForbidden();
        $this->actingAs($this->owner)->get("{$this->base}/".Server::factory()->create()->id)->assertNotFound();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string|int>
     */
    private function server(array $overrides = []): array
    {
        return ['provider_id' => $this->provider->id, 'type' => 'app', 'name' => 'db-1', 'region' => 'fra1', 'size' => 's-1vcpu-1gb', 'image' => 'ubuntu-24-04-x64', ...$overrides];
    }
}
