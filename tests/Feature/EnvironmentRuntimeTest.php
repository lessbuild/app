<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ConfigureProcessesScript;
use App\Scripts\Repository\ConfigureWebRuntimeScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\SyncEnvironmentScript;
use App\Services\DeploymentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EnvironmentRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_encrypted_runtime_configuration_is_snapshotted_into_a_deployment(): void
    {
        [$owner, $repository, $environment] = $this->application();
        $this->actingAs($owner)->post(route('environments.variables.store', $environment), [
            'key' => 'API_TOKEN', 'value' => 'top-secret-token', 'is_secret' => '1',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('environments.resources.store', $environment), [
            'name' => 'cache', 'type' => 'redis', 'is_managed' => '0',
            'variables' => "REDIS_HOST=cache.internal\nREDIS_PASSWORD=redis-secret",
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('environments.processes.store', $environment), [
            'name' => 'emails', 'type' => 'worker', 'command' => 'php artisan queue:work --queue=emails',
            'replicas' => 2, 'is_enabled' => '1',
        ])->assertRedirect();

        $attributes = app(DeploymentRequest::class)->attributes($repository, $owner);
        $build = $repository->builds()->create(['trigger_source' => Build::TRIGGER_MANUAL, ...$attributes]);
        $this->assertSame('top-secret-token', $build->environment_payload['variables']['API_TOKEN']);
        $this->assertNotSame('top-secret-token', DB::table('builds')->where('id', $build->id)->value('environment_payload'));

        $environmentScript = (new SyncEnvironmentScript)->script(1, $build);
        $processScript = (new ConfigureProcessesScript)->script(2, $build);
        $this->assertStringNotContainsString('top-secret-token', $environmentScript);
        $this->assertStringNotContainsString('redis-secret', $environmentScript);
        $this->assertStringContainsString('base64 --decode', $environmentScript);
        $this->assertStringContainsString('buildpusher-', $processScript);
        $this->assertStringContainsString('active-units', $processScript);
        $this->assertStringContainsString('systemctl enable --now', $processScript);
        $this->assertShellSyntax($environmentScript);
        $this->assertShellSyntax($processScript);
    }

    public function test_application_preset_creates_worker_and_scheduler_definitions(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Storefront', 'preset' => 'laravel-inertia',
        ])->assertRedirect();
        $project = $owner->currentOrganization->projects()->sole();
        $this->assertSame('laravel-inertia', $project->preset);
        $this->assertEqualsCanonicalizing(['queue', 'scheduler'], $project->environments()->sole()->processes()->pluck('name')->all());
    }

    public function test_application_templates_create_node_and_docker_runtime_configuration(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Frontend', 'preset' => 'nextjs',
        ])->assertRedirect();
        $next = $owner->currentOrganization->projects()->where('preset', 'nextjs')->sole()->environments()->sole();
        $this->assertSame('node', $next->runtime_type);
        $this->assertSame('npm run build', $next->build_command);
        $this->assertSame('npm start', $next->start_command);
        $this->assertSame(3000, $next->container_port);

        $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Container', 'preset' => 'docker',
        ])->assertRedirect();
        $docker = $owner->currentOrganization->projects()->where('preset', 'docker')->sole()->environments()->sole();
        $this->assertSame('docker', $docker->runtime_type);
        $this->assertSame('Dockerfile', $docker->dockerfile_path);
        $this->assertSame(8080, $docker->container_port);
    }

    public function test_runtime_settings_are_validated_and_snapshotted(): void
    {
        [$owner, $repository, $environment] = $this->application();
        $this->actingAs($owner)->patch(route('environments.update', $environment), [
            'name' => 'Production', 'type' => 'production', 'branch' => 'main',
            'server_id' => $environment->server_id, 'website_id' => $environment->website_id,
            'is_protected' => 0, 'requires_deployment_approval' => 0,
            'minimum_replicas' => 1, 'maximum_replicas' => 1,
            'runtime_type' => 'docker', 'container_port' => 8080,
            'dockerfile_path' => '../Dockerfile',
        ])->assertSessionHasErrors('dockerfile_path');

        $this->actingAs($owner)->patch(route('environments.update', $environment), [
            'name' => 'Production', 'type' => 'production', 'branch' => 'main',
            'server_id' => $environment->server_id, 'website_id' => $environment->website_id,
            'is_protected' => 0, 'requires_deployment_approval' => 0,
            'minimum_replicas' => 1, 'maximum_replicas' => 1,
            'runtime_type' => 'node', 'runtime_version' => '20',
            'build_command' => 'npm run build', 'start_command' => 'npm start', 'container_port' => 3000,
        ])->assertRedirect();

        $payload = app(DeploymentRequest::class)->attributes($repository, $owner)['environment_payload']['runtime'];
        $this->assertSame('node', $payload['type']);
        $this->assertSame('20', $payload['version']);
        $this->assertSame('npm start', $payload['start_command']);
    }

    public function test_node_and_docker_scripts_build_health_check_and_switch_versioned_runtimes(): void
    {
        [, $repository, $environment] = $this->application();
        foreach ([
            ['type' => 'node', 'version' => '20', 'build_command' => 'npm run build', 'start_command' => 'npm start', 'container_port' => 3000],
            ['type' => 'docker', 'dockerfile_path' => 'deploy/Dockerfile', 'container_port' => 8080],
        ] as $runtime) {
            $build = $repository->builds()->create([
                'environment_id' => $environment->id,
                'status' => Build::STATUS_RUNNING,
                'environment_payload' => ['runtime' => $runtime],
            ]);
            $dependencies = (new InstallDependenciesScript)->script(1, $build);
            $web = (new ConfigureWebRuntimeScript)->script(2, $build);
            $this->assertStringContainsString($runtime['type'] === 'docker' ? 'docker build --pull' : 'npm', $dependencies);
            $this->assertStringContainsString('reverse_proxy 127.0.0.1:', base64_decode($this->encodedCaddyPayload($web)) ?: $web);
            $this->assertStringContainsString('Candidate web runtime did not become healthy', $web);
            $this->assertStringContainsString('web-runtime', $web);
            $this->assertShellSyntax($dependencies);
            $this->assertShellSyntax($web);
        }
    }

    public function test_secrets_are_versioned_scoped_and_snapshotted_without_plaintext_storage(): void
    {
        [$owner, $repository, $environment] = $this->application();
        foreach ([
            ['key' => 'BUILD_TOKEN', 'value' => 'build-secret', 'scope' => 'build'],
            ['key' => 'RUNTIME_TOKEN', 'value' => 'runtime-v1', 'scope' => 'runtime'],
            ['key' => 'SHARED_TOKEN', 'value' => 'shared-secret', 'scope' => 'all'],
        ] as $variable) {
            $this->actingAs($owner)->post(route('environments.variables.store', $environment), [
                ...$variable, 'is_secret' => '1',
            ])->assertRedirect();
        }
        $this->actingAs($owner)->post(route('environments.variables.store', $environment), [
            'key' => 'RUNTIME_TOKEN',
            'value' => 'runtime-v2',
            'scope' => 'runtime',
            'rotation_due_at' => now()->addMonth()->toDateString(),
            'is_secret' => '1',
        ])->assertRedirect();

        $runtime = $environment->variables()->where('key', 'RUNTIME_TOKEN')->sole();
        $this->assertSame(2, $runtime->current_version);
        $this->assertNotNull($runtime->rotated_at);
        $this->assertCount(2, $runtime->versions);
        $this->assertEqualsCanonicalizing(['runtime-v1', 'runtime-v2'], $runtime->versions->pluck('value')->all());
        $this->assertStringNotContainsString('runtime-v2', DB::table('environment_variables')->where('id', $runtime->id)->value('value'));
        $this->assertStringNotContainsString('runtime-v1', DB::table('environment_variable_versions')->where('environment_variable_id', $runtime->id)->value('value'));

        $payload = app(DeploymentRequest::class)->attributes($repository, $owner)['environment_payload'];
        $this->assertSame([
            'RUNTIME_TOKEN' => 'runtime-v2',
            'SHARED_TOKEN' => 'shared-secret',
        ], $payload['variables']);
        $this->assertSame([
            'BUILD_TOKEN' => 'build-secret',
            'SHARED_TOKEN' => 'shared-secret',
        ], $payload['build_variables']);

        $build = $repository->builds()->create(['status' => Build::STATUS_RUNNING, 'environment_payload' => $payload]);
        $sync = (new SyncEnvironmentScript)->script(1, $build);
        $activate = (new ActivateReleaseScript)->script(2, $build);
        $this->assertStringNotContainsString('build-secret', $sync);
        $this->assertStringContainsString('.build.env', $sync);
        $this->assertStringContainsString('rm -f -- "$DEPLOY_ROOT/.build.env"', $activate);
    }

    public function test_process_commands_are_never_rendered_on_the_application_screen(): void
    {
        [$owner, , $environment] = $this->application();
        $environment->processes()->create([
            'name' => 'private-worker', 'type' => 'worker',
            'command' => 'php artisan queue:work --token=do-not-render',
            'replicas' => 1, 'is_enabled' => true,
        ]);

        $this->actingAs($owner)->get(route('projects.show', $environment->project))
            ->assertOk()
            ->assertSee('Command encrypted')
            ->assertDontSee('do-not-render');
    }

    public function test_application_canvas_exposes_source_readiness_and_direct_deployment(): void
    {
        [$owner, $repository, $environment] = $this->application();

        $this->actingAs($owner)->get(route('projects.show', $environment->project))
            ->assertOk()
            ->assertSee($repository->name)
            ->assertSee(route('repositories.show', $repository), false)
            ->assertSee(route('repositories.deploy', $repository), false)
            ->assertSee('Deploy now');
    }

    private function application(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub', 'provider' => Provider::TYPE_GITHUB, 'token' => 'token', 'description' => 'Source',
        ]);
        $server = $owner->servers()->create(['name' => 'Production', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Application', 'description' => 'Website',
            'environment' => 'APP_ENV=production', 'url' => 'app.example.com', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Source',
            'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Source',
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id, 'name' => 'Application', 'slug' => 'application', 'preset' => 'custom',
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'server_id' => $server->id, 'website_id' => $website->id,
        ]);

        return [$owner, $repository, $environment];
    }

    private function assertShellSyntax(string $script): void
    {
        $process = new Process(['bash', '-n']);
        $process->setInput($script);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    private function encodedCaddyPayload(string $script): string
    {
        if (! preg_match("/printf '%s' '([^']*)' \| base64 --decode > '\/etc\/caddy/", $script, $matches)) {
            return '';
        }

        return $matches[1];
    }
}
