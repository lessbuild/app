<?php

namespace Tests\Feature;

use App\Jobs\ApplyLoadBalancerJob;
use App\Jobs\Database\CollectDatabaseSnapshotJob;
use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlatformExpansionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false, 'billing.enforce_limits' => false]);
    }

    public function test_database_inspection_is_scoped_and_stored_without_exposing_credentials(): void
    {
        [$owner, , , $resource] = $this->infrastructure();
        $runner = $this->runner("size_bytes=1048576\nactive_connections=3\nschema_table=public.users\n");
        (new CollectDatabaseSnapshotJob($resource->id))->handle($runner);

        $this->assertSame(1048576, $resource->snapshots()->sole()->size_bytes);
        $this->actingAs($owner)->get(route('databases.index'))->assertOk()->assertSee('public.users')->assertDontSee('database-secret');
        $this->actingAs(User::factory()->create())->get(route('databases.index'))->assertOk()->assertDontSee($resource->name);
    }

    public function test_load_balancer_generates_health_checked_multi_node_configuration(): void
    {
        [$owner, $server, $environment] = $this->infrastructure();
        $lbServer = $owner->servers()->create(['name' => 'Edge', 'public_ip' => '203.0.113.20', 'ssh_private_key' => 'key', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $second = $owner->servers()->create(['name' => 'Node two', 'public_ip' => '203.0.113.30', 'ssh_private_key' => 'key', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $balancer = $owner->currentOrganization->loadBalancers()->create(['environment_id' => $environment->id, 'server_id' => $lbServer->id, 'created_by' => $owner->id, 'hostname' => 'ha.example.com', 'health_path' => '/health']);
        $balancer->nodes()->createMany([
            ['server_id' => $server->id, 'upstream_port' => 80, 'weight' => 1, 'is_enabled' => true],
            ['server_id' => $second->id, 'upstream_port' => 8080, 'weight' => 2, 'is_enabled' => true],
        ]);
        $captured = '';
        (new ApplyLoadBalancerJob($balancer->id))->handle($this->runner('', function (string $script) use (&$captured): void {
            $captured = $script;
        }));

        $this->assertSame('active', $balancer->fresh()->status);
        $decoded = base64_decode(str($captured)->match("/printf '%s' '([^']+)'/"));
        $this->assertStringContainsString('health_uri /health', $decoded);
        $this->assertStringContainsString('203.0.113.10:80', $decoded);
        $this->assertSame(2, substr_count($decoded, '203.0.113.30:8080'));
    }

    public function test_security_policy_prevents_lockout_and_encrypts_ip_ranges(): void
    {
        [$owner] = $this->infrastructure();
        $this->actingAs($owner)->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->patch(route('organizations.security-policy.update'), [
            'allowed_ip_ranges' => '198.51.100.0/24', 'allowed_email_domains' => 'example.com',
            'require_two_factor' => '0', 'sso_enforced' => '0', 'session_idle_minutes' => 30,
        ])->assertSessionHasErrors('allowed_ip_ranges');
        $this->actingAs($owner)->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->patch(route('organizations.security-policy.update'), [
            'allowed_ip_ranges' => '203.0.113.0/24', 'allowed_email_domains' => 'example.com',
            'require_two_factor' => '0', 'sso_enforced' => '0', 'session_idle_minutes' => 30,
        ])->assertRedirect();
        $organization = $owner->currentOrganization->fresh();
        $this->assertSame(['203.0.113.0/24'], $organization->allowed_ip_ranges);
        $this->assertStringNotContainsString('203.0.113.0', (string) DB::table('organizations')->where('id', $organization->id)->value('allowed_ip_ranges'));
    }

    public function test_scheduled_task_and_new_control_plane_endpoints_are_available(): void
    {
        Queue::fake();
        [$owner, , $environment] = $this->infrastructure();
        $this->actingAs($owner)->post(route('automation.tasks.store', $environment), [
            'name' => 'Warm cache', 'cron_expression' => '*/5 * * * *', 'timezone' => 'UTC',
            'command' => 'php artisan cache:warm', 'timeout_seconds' => 120,
            'without_overlapping' => '1', 'alert_on_failure' => '1',
        ])->assertRedirect();
        $task = $environment->scheduledTasks()->sole();
        $this->assertNotSame($task->command, DB::table('scheduled_tasks')->where('id', $task->id)->value('command'));

        $token = $owner->createToken('automation', ['read', 'deploy', 'manage'])->plainTextToken;
        $this->withToken($token)->putJson('/api/v1/environments/'.$environment->id.'/variables', ['variables' => "APP_ENV=production\nAPI_KEY=secret"])
            ->assertOk()->assertJsonPath('data.count', 2);
    }

    /** @return array{User, Server, Environment, EnvironmentResource} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create(['name' => 'Cloud', 'provider' => Provider::TYPE_DIGITALOCEAN, 'token' => 'token', 'description' => 'Infrastructure']);
        $server = $owner->servers()->create(['provider_id' => $provider->id, 'name' => 'Node one', 'public_ip' => '203.0.113.10', 'ssh_private_key' => 'key', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create(['server_id' => $server->id, 'name' => 'Application', 'description' => 'Production application', 'environment' => '', 'url' => 'app.example.com', 'database_password' => 'database-secret', 'provisioning_status' => Website::STATUS_ACTIVE]);
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'Application', 'slug' => 'application']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main', 'server_id' => $server->id, 'website_id' => $website->id, 'is_protected' => true]);
        $resource = $environment->resources()->create(['name' => 'database-alpha-unique', 'type' => 'postgresql', 'is_managed' => true, 'status' => 'ready', 'configuration' => ['variables' => ['DB_DATABASE' => 'application', 'DB_USERNAME' => 'application', 'DB_PASSWORD' => 'database-secret']]]);

        return [$owner, $server, $environment, $resource];
    }

    private function runner(string $output, ?callable $capture = null): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->andReturnTrue();
        $process->shouldReceive('getOutput')->andReturn($output);
        $process->shouldReceive('getErrorOutput')->andReturn('');
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->withArgs(function (string $script) use ($capture): bool {
            if ($capture) {
                $capture($script);
            }

            return true;
        })->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }
}
