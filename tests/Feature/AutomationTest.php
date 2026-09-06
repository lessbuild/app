<?php

namespace Tests\Feature;

use App\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Jobs\WakeHibernatedEnvironmentJob;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Entitlements;
use App\Services\ManagedSsh;
use App\Services\Runner;
use App\Services\WorkflowConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_workflow_yaml_applies_schedules_scaling_and_processes_atomically(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Store', 'slug' => 'store', 'preset' => 'laravel']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);
        $yaml = <<<'YAML'
        version: 1
        environments:
          production:
            deployment:
              cron: '0 3 * * 1-5'
              timezone: UTC
            scale:
              minimum: 1
              maximum: 4
              desired: 2
              hibernate_after_minutes: 60
            scaling_schedules:
              - name: morning
                cron: '0 8 * * 1-5'
                timezone: UTC
                replicas: 4
            processes:
              emails:
                type: worker
                command: php artisan queue:work --queue=emails
                replicas: 2
        YAML;

        app(WorkflowConfiguration::class)->apply($project, $yaml, $user->id);

        $environment->refresh();
        $this->assertSame(2, $environment->desired_replicas);
        $this->assertSame(60, $environment->hibernate_after_minutes);
        $this->assertSame('0 3 * * 1-5', $environment->deploymentSchedules()->sole()->cron_expression);
        $this->assertSame(4, $environment->scalingSchedules()->sole()->replicas);
        $this->assertSame('emails', $environment->processes()->sole()->name);
        $this->assertNotSame($yaml, DB::table('projects')->where('id', $project->id)->value('workflow_yaml'));
    }

    public function test_api_is_scoped_and_honors_token_abilities(): void
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'API App', 'slug' => 'api-app', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main', 'minimum_replicas' => 1, 'maximum_replicas' => 3]);
        $outsider = User::factory()->create();
        $outsider->currentOrganization->projects()->create(['created_by' => $outsider->id, 'name' => 'Private App', 'slug' => 'private', 'preset' => 'custom']);
        Sanctum::actingAs($owner, ['read']);

        $this->getJson('/api/v1/projects')->assertOk()->assertJsonFragment(['name' => 'API App'])->assertJsonMissing(['name' => 'Private App']);
        $this->getJson('/api/v1/projects/'.$project->id)->assertOk();
        $this->patchJson('/api/v1/environments/'.$environment->id.'/scale', ['replicas' => 2])->assertForbidden();
    }

    public function test_due_scaling_schedule_is_claimed_once_and_queues_runtime_change(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Scale', 'slug' => 'scale', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main', 'minimum_replicas' => 1, 'maximum_replicas' => 5]);
        $schedule = $environment->scalingSchedules()->create(['created_by' => $user->id, 'name' => 'Now', 'replicas' => 3, 'cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => true]);

        $this->artisan('buildpusher:scaling:scheduled')->assertSuccessful();
        $this->artisan('buildpusher:scaling:scheduled')->assertSuccessful();

        $this->assertSame(3, $environment->fresh()->desired_replicas);
        $this->assertNotNull($schedule->fresh()->last_run_at);
        Queue::assertPushed(ApplyEnvironmentRuntimeStateJob::class, 1);
    }

    public function test_automation_screen_is_compact_and_available_to_authenticated_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('automation.index'))->assertOk()->assertSee('Application workflows')->assertSee('Personal access tokens');
    }

    public function test_owner_can_create_expiring_token_and_rotate_it(): void
    {
        $user = User::factory()->create();

        $create = $this->actingAs($user)->post(route('automation.tokens.store'), [
            'name' => 'Release bot',
            'abilities' => ['read', 'deploy'],
            'expires_in_days' => 90,
        ]);

        $create->assertRedirect()->assertSessionHas('plainTextToken');
        $token = $user->tokens()->sole();
        $this->assertTrue($token->expires_at->isBetween(now()->addDays(89), now()->addDays(91)));
        $oldHash = $token->token;

        $rotate = $this->post(route('automation.tokens.rotate', $token));

        $rotate->assertRedirect()->assertSessionHas('plainTextToken');
        $replacement = $user->tokens()->sole();
        $this->assertNotSame($token->id, $replacement->id);
        $this->assertNotSame($oldHash, $replacement->token);
        $this->assertSame(['read', 'deploy'], $replacement->abilities);
        $this->assertTrue($replacement->expires_at->isBetween(now()->addMonths(11), now()->addMonths(13)));
    }

    public function test_api_reference_and_openapi_document_are_public(): void
    {
        $this->get(route('api-docs'))->assertOk()->assertSee('Control plane API')->assertSee('/openapi.json');
        $this->get('/openapi.json')->assertOk()->assertHeader('Content-Type', 'application/json');
        $document = json_decode(file_get_contents(public_path('openapi.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/environments/{environment}/deploy', $document['paths']);
        $this->assertArrayHasKey('/deployments/{build}/promote', $document['paths']);
    }

    public function test_hibernated_environment_wakes_after_a_real_incoming_request(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $provider = $user->providers()->create(['name' => 'Cloud', 'description' => 'Runtime provider', 'provider' => Provider::TYPE_DIGITALOCEAN, 'token' => 'token']);
        $server = $user->servers()->create(['name' => 'Runtime', 'provider_id' => $provider->id, 'public_ip' => '192.0.2.10', 'ssh_private_key' => 'key', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $user->websites()->create(['name' => 'App', 'description' => 'Runtime app', 'server_id' => $server->id, 'url' => 'app.example.test', 'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE]);
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'Wake', 'slug' => 'wake', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'branch' => 'main', 'website_id' => $website->id, 'server_id' => $server->id, 'hibernated_at' => now()->subMinute()]);

        $process = new Process(['printf', (string) now()->getTimestamp()]);
        $process->run();
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->withArgs(fn (Server $value): bool => $value->is($server))->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        (new WakeHibernatedEnvironmentJob($environment->id))->handle($runner, app(Entitlements::class));

        Queue::assertPushed(ApplyEnvironmentRuntimeStateJob::class, fn (ApplyEnvironmentRuntimeStateJob $job): bool => $job->environmentId === $environment->id && $job->hibernate === false);
    }
}
