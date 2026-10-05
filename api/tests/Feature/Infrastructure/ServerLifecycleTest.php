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
use App\Models\ServerMetric;
use App\Models\User;
use App\Models\Website;
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
        $this->base = "/api/app/projects/{$this->project->id}/infrastructure/servers";
        $this->onTier($this->project, 'deploy', 'pro');
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\RequirePassword::class);
    }

    /**
     * Creating a server registers a key and boots it with the provisioning script.
     */
    public function test_creating_a_server_registers_a_key_and_boots_it_with_the_provisioning_script(): void
    {
        $this->actingAs($this->owner)->postJson($this->base, $this->server(['type' => 'database']))->assertSuccessful()->assertJsonStructure(['secrets' => ['root', 'mysql']]);

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
        $this->actingAs($this->owner)->getJson("{$this->base}/{$server->id}")->assertOk()->assertJsonPath('server.provisioning', true)->assertJsonPath('server.ip', '203.0.113.50');
    }

    /**
     * Callbacks move a server through its stages and ignore stale attempts.
     */
    public function test_callbacks_move_a_server_through_its_stages_and_ignore_stale_attempts(): void
    {
        $server = Server::factory()->provisioning()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'type' => ServerType::Cache]);
        $status = $this->callbackUrl($server, 'status');

        $this->post($status, ['status' => 3])->assertNoContent();
        $this->assertSame(3, $this->reload($server)->setup_stage);
        // The page follows along live: the status endpoint names the step running now (the one after the last confirmed).
        $final = app(\App\Services\Infrastructure\ServerProvisioningPlan::class)->finalStage($server);
        $this->actingAs($this->owner)->getJson("{$this->base}/{$server->id}")->assertOk()->assertJsonPath('server.provisioning', true)->assertJsonPath('server.step', 'Installing Redis');
        $this->actingAs($this->owner)->getJson("{$this->base}/{$server->id}/status")->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['ssh' => 'root@'.$server->public_ip.':'.$server->ssh_port, 'status' => 'provisioning', 'stage' => 3, 'final_stage' => $final, 'step' => 'Installing Redis', 'reason' => null, 'finished' => false]);
        $this->post($this->callbackUrl($server, 'log'), ['log' => "Installing redis\n"])->assertNoContent();
        $this->assertSame("Installing redis\n", $server->logSnapshots()->sole()->log);
        $this->post(str_replace('status', 'failed', $status), ['message' => 'x'])->assertForbidden();
        $this->post("/servers/{$server->id}/provisioning/callback/status", ['status' => 1])->assertForbidden();

        $this->post($status, ['status' => $final])->assertNoContent();
        $this->assertSame(Server::STATUS_ACTIVE, $this->reload($server)->provisioning_status);
        $this->assertNotNull($this->reload($server)->provisioned_at);
        $this->actingAs($this->owner)->getJson("{$this->base}/{$server->id}/status")->assertJson(['status' => 'active', 'step' => null, 'finished' => true]);
        $this->actingAs($this->owner)->getJson("{$this->base}/{$server->id}")->assertJsonPath('server.provisioning', false);

        $this->post($this->callbackUrl($server, 'failed'), ['message' => 'Too late'])->assertNoContent();
        $this->assertSame(Server::STATUS_ACTIVE, $this->reload($server)->provisioning_status);
        $this->reload($server)->forceFill(['provisioning_status' => Server::STATUS_PROVISIONING, 'provisioning_token' => '00000000-0000-0000-0000-000000000001'])->save();
        $this->post($status, ['status' => 1])->assertNoContent();
        $this->assertSame(Server::STATUS_PROVISIONING, $this->reload($server)->provisioning_status);
    }

    /**
     * A remote failure can be retried over ssh from the failed stage.
     */
    public function test_a_remote_failure_can_be_retried_over_ssh_from_the_failed_stage(): void
    {
        $server = Server::factory()->provisioning()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'setup_stage' => 5]);
        $this->post($this->callbackUrl($server, 'failed'), ['message' => 'apt failed', 'exit_code' => 100])->assertNoContent();
        $this->assertSame(Server::FAILURE_REMOTE, $this->reload($server)->provisioning_failure_phase);
        $this->assertSame('apt failed (exit code 100)', $this->reload($server)->provisioning_error);

        $this->actingAs($this->owner)->postJson("{$this->base}/{$server->id}/provisioning/retry")->assertSuccessful()->assertSessionMissing('secrets');

        $server->refresh();
        $this->assertSame(Server::STATUS_PROVISIONING, $server->provisioning_status);
        $this->assertSame(4242, $server->provisioning_process_id);
        $this->assertCount(1, $this->scripts->started);
        $this->assertStringContainsString("provisionPing {$server->id} 6", $this->scripts->started[0]['script']);
        $this->assertStringNotContainsString("provisionPing {$server->id} 5\n", $this->scripts->started[0]['script']);
        $this->actingAs($this->owner)->postJson("{$this->base}/{$server->id}/provisioning/retry")->assertSuccessful();
    }

    /**
     * Failed creation cleans up and failed initialisation can be retried.
     */
    public function test_failed_creation_cleans_up_and_failed_initialisation_can_be_retried(): void
    {
        $this->cloud->failCreate = true;
        $this->actingAs($this->owner)->postJson($this->base, $this->server())->assertSuccessful();
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
        $this->actingAs($this->owner)->postJson("{$this->base}/{$server->id}/initialization/retry")->assertSuccessful();
        $this->assertSame('198.51.100.7', $this->reload($server)->public_ip);
        $this->assertSame(Server::STATUS_PROVISIONING, $this->reload($server)->provisioning_status);
    }

    /**
     * Initialisation waits for a running server with a real address and says why.
     */
    public function test_initialisation_waits_for_a_running_server_with_a_real_address_and_says_why(): void
    {
        $server = Server::factory()->provisioning(Server::STATUS_QUEUED)->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'public_ip' => null]);
        $job = new \App\Jobs\Infrastructure\InitialiseServer($server->id, (string) $server->initialization_token);
        $this->assertTrue($job->retryUntil() > now()->addMinutes(15));

        // Booting with an address already assigned, then ready but with a placeholder address: keep waiting either way.
        foreach ([['203.0.113.50', 'not_ready', 'The provider is still starting the server.'], ['0.0.0.0', 'ready', 'The provider hasn’t given the server a public IP yet.']] as [$ip, $readiness, $reason]) {
            [$this->cloud->publicIp, $this->cloud->readiness] = [$ip, $readiness];
            try {
                $job->handle(app(\App\Services\Infrastructure\ServerProviderResolver::class), app(\App\Services\Infrastructure\SshHostIdentity::class));
                $this->fail('Should keep waiting.');
            } catch (RuntimeException $exception) {
                $this->assertSame($reason, $exception->getMessage());
            }
            $server->refresh();
            $this->assertSame([Server::STATUS_WAITING_FOR_IP, $reason, null], [$server->provisioning_status, $server->provisioning_error, $server->public_ip]);
        }
        $this->actingAs($this->owner)->getJson("{$this->base}/{$server->id}")->assertOk()
            ->assertJsonPath('server.error', 'The provider hasn’t given the server a public IP yet.')->assertJsonPath('server.provisioning', true);
        $this->actingAs($this->owner)->getJson("{$this->base}/{$server->id}/status")->assertJson(['status' => 'waiting_for_ip', 'step' => null, 'reason' => 'The provider hasn’t given the server a public IP yet.', 'public_ip' => null]);

        [$this->cloud->publicIp, $this->cloud->readiness] = ['203.0.113.50', 'ready'];
        $job->handle(app(\App\Services\Infrastructure\ServerProviderResolver::class), app(\App\Services\Infrastructure\SshHostIdentity::class));
        $server->refresh();
        $this->assertSame([Server::STATUS_PROVISIONING, null, '203.0.113.50'], [$server->provisioning_status, $server->provisioning_error, $server->public_ip]);
    }

    /**
     * Deleting a server removes it at the provider first.
     */
    public function test_deleting_a_server_removes_it_at_the_provider_first(): void
    {
        $server = Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'identifier' => 'cloud-9', 'ssh_fingerprint' => 'key-9']);
        $this->cloud->failDelete = true;
        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$server->id}")->assertJsonValidationErrors(['server' => 'Fake Cloud couldn’t delete the server.']);
        $this->assertModelExists($server);

        $this->cloud->failDelete = false;
        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$server->id}")->assertJsonRedirect($this->base);
        $this->assertModelMissing($server);
        $this->assertContains('cloud-9', $this->cloud->deletedServers);
        $this->assertContains('key-9', $this->cloud->deletedKeys);
    }

    /**
     * The create page reads the catalog and the plan limits servers.
     */
    public function test_the_create_page_reads_the_catalog_and_the_plan_limits_servers(): void
    {
        $this->app->forgetInstance(\App\Services\Infrastructure\ServerProviderResolver::class);
        $this->app->offsetUnset(\App\Services\Infrastructure\ServerProviderResolver::class);
        Http::fake([
            'https://api.digitalocean.com/v2/regions*' => Http::response(['regions' => [['slug' => 'fra1', 'name' => 'Frankfurt 1', 'available' => true]]]),
            'https://api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [['slug' => 's-1vcpu-1gb', 'description' => 'Basic', 'memory' => 1024, 'vcpus' => 1, 'price_monthly' => 6]]]),
            'https://api.digitalocean.com/v2/images*' => Http::response(['images' => [['slug' => 'ubuntu-24-04-x64', 'distribution' => 'Ubuntu', 'name' => '24.04 (LTS) x64'], ['slug' => 'debian-12', 'distribution' => 'Debian', 'name' => '12']]]),
        ]);
        $this->actingAs($this->owner)->getJson("{$this->base}/create")->assertOk()
            ->assertJsonPath('catalog.regions.0.label', 'Frankfurt 1')->assertJsonPath('catalog.sizes.0.label', 'Basic · 1 GB RAM · 1 vCPU · $6/month')
            ->assertJsonPath('catalog.images', fn (array $images): bool => count($images) === 1 && $images[0]['label'] === 'Ubuntu 24.04 (LTS) x64');
        $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertJsonPath('canManage', true)->assertDontSee('Frankfurt 1');

        $this->onTier($this->project, 'deploy', 'free');
        Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id]);
        $this->actingAs($this->owner)->postJson($this->base, $this->server())->assertJsonValidationErrors('plan');
        $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertJsonPath('limit', 1)->assertJsonCount(1, 'servers');
    }

    /**
     * Viewers see servers but cant manage them.
     */
    public function test_viewers_see_servers_but_cant_manage_them(): void
    {
        $server = Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'display_name' => 'Primary web']);
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);

        $this->actingAs($viewer)->getJson($this->base)->assertOk()->assertJsonPath('servers.0.name', 'Primary web')->assertJsonPath('canManage', false);
        $this->actingAs($viewer)->getJson("{$this->base}/{$server->id}")->assertOk()->assertJsonPath('canDelete', false)->assertJsonPath('canManage', false);
        $this->actingAs($viewer)->getJson("{$this->base}/{$server->id}/status")->assertOk()->assertJson(['finished' => true]);
        $this->actingAs($viewer)->getJson("{$this->base}/create")->assertForbidden();
        $this->actingAs($viewer)->postJson($this->base, $this->server())->assertForbidden();
        $this->actingAs($viewer)->putJson("{$this->base}/{$server->id}", ['display_name' => 'Mine'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("{$this->base}/{$server->id}")->assertForbidden();
        $theirs = Server::factory()->create()->id;
        $this->actingAs($this->owner)->getJson("{$this->base}/{$theirs}")->assertNotFound();
        $this->actingAs($this->owner)->getJson("{$this->base}/{$theirs}/status")->assertNotFound();
    }

    /**
     * The servers list shows each server's latest CPU, memory and disk use, its monthly cost, whether it was imported
     * and how far set-up has got.
     */
    public function test_the_servers_list_shows_usage_cost_and_set_up_progress(): void
    {
        $server = Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'monthly_cost' => 12.5, 'monthly_cost_currency' => 'EUR']);
        (new ServerMetric)->forceFill(['server_id' => $server->id, 'load_1m' => 1, 'load_5m' => 1, 'load_15m' => 1, 'cpu_percent' => 10, 'memory_percent' => 20, 'disk_percent' => 30, 'uptime_seconds' => 100, 'recorded_at' => now()->subMinutes(5)])->save();
        (new ServerMetric)->forceFill(['server_id' => $server->id, 'load_1m' => 1, 'load_5m' => 1, 'load_15m' => 1, 'cpu_percent' => 42, 'memory_percent' => 55, 'disk_percent' => 61, 'uptime_seconds' => 400, 'recorded_at' => now()->subMinute()])->save();
        Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => null, 'created_at' => now()->addSecond()]);

        $page = $this->actingAs($this->owner)->getJson($this->base)->assertOk();
        $listed = collect((array) $page->json('servers'))->keyBy('id');
        $this->assertSame(['cpu' => 42, 'memory' => 55, 'disk' => 61], $listed[$server->id]['usage']);
        $this->assertFalse($listed[$server->id]['imported']);
        $this->assertSame('EUR', $listed[$server->id]['currency']);
        $this->assertEquals(12.5, $listed[$server->id]['monthlyCost']);
        $this->assertIsInt($listed[$server->id]['finalStage']);
        $imported = $listed->firstWhere('id', '!=', $server->id);
        $this->assertTrue($imported['imported']);
        $this->assertNull($imported['usage']);
    }

    /**
     * A server's page lists the websites on it, the projects they belong to and what the server costs a month.
     */
    public function test_a_servers_page_shows_its_websites_projects_and_cost(): void
    {
        $server = Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->provider->id, 'monthly_cost' => 7.59, 'monthly_cost_currency' => 'EUR']);
        $production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        Website::factory()->create(['account_id' => $this->project->account_id, 'server_id' => $server->id, 'environment_id' => $production->id, 'url' => 'shop.example.com']);

        $this->actingAs($this->owner)->getJson("{$this->base}/{$server->id}")->assertOk()
            ->assertJsonPath('websites.0.url', 'shop.example.com')
            ->assertJsonPath('usedBy.0', $this->project->name)
            ->assertJsonPath('currency', 'EUR');
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
