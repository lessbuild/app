<?php

namespace Tests\Feature;

use App\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Jobs\Web\RefreshWebsiteLogJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogSnapshot;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\CloneRepositoryScript;
use App\Scripts\Repository\ConfigureProcessesScript;
use App\Scripts\Repository\ConfigureWebRuntimeScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\PreviewInitializationScript;
use App\Scripts\Repository\RunBuildCommandsScript;
use App\Scripts\Repository\RunPostDeploymentCommandsScript;
use App\Scripts\Repository\SymlinkScript;
use App\Scripts\Repository\ValidateCandidateScript;
use App\Scripts\Web\AddWebsiteToCaddyScript;
use App\Services\ApplicationConfigurationRepositoryIdentity;
use App\Services\DeploymentRequest;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RepositoryDeploymentRootTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_root_is_normalized_snapshotted_and_included_in_configuration_identity(): void
    {
        [$owner, $provider, , $website] = $this->infrastructure();

        $this->actingAs($owner)->post(route('repositories.store'), [
            ...$this->payload($provider, $website),
            'deployment_root' => './apps/storefront',
        ])->assertRedirect();

        $repository = Repository::query()->sole();
        $this->assertSame('apps/storefront', $repository->deployment_root);
        $this->assertSame('apps/storefront', $repository->deploymentRoot());

        $this->actingAs($owner)->patch(route('repositories.update', $repository), $this->payload($provider, $website))
            ->assertRedirect();
        $this->assertSame('apps/storefront', $repository->fresh()->deployment_root);

        $attributes = app(DeploymentRequest::class)->attributesForEnvironment($repository, null, $owner);
        $this->assertSame(['repository_root' => 'apps/storefront'], $attributes['environment_payload']);

        $build = $repository->builds()->create([
            ...$attributes,
            'trigger_source' => Build::TRIGGER_MANUAL,
        ]);
        $this->assertSame('apps/storefront', $build->deploymentRoot());
        $this->assertSame('/var/www/active-website/setup/apps/storefront', $build->deploymentPath('setup'));

        $fingerprint = ApplicationConfigurationRepositoryIdentity::fingerprint($repository);
        $repository->update(['deployment_root' => 'services/api']);
        $this->assertNotSame($fingerprint, ApplicationConfigurationRepositoryIdentity::fingerprint($repository->fresh()));
        $this->assertSame('apps/storefront', $build->fresh()->deploymentRoot());
    }

    public function test_service_root_is_applied_to_build_runtime_and_caddy_paths(): void
    {
        [$owner, $provider, , $website] = $this->infrastructure();
        $repository = $owner->repositories()->create([
            ...$this->payload($provider, $website),
            'deployment_root' => 'apps/storefront',
            'build_commands' => 'npm run build',
            'post_deployment_commands' => 'php artisan queue:restart',
        ]);
        $build = $repository->builds()->create([
            'status' => Build::STATUS_RUNNING,
            'environment_payload' => [
                'repository_root' => 'apps/storefront',
                'runtime' => ['type' => 'php', 'deployment_strategy' => 'canary'],
                'processes' => [[
                    'name' => 'queue',
                    'type' => 'worker',
                    'command' => 'php artisan queue:work',
                    'replicas' => 1,
                ]],
                'preview_initialization' => [
                    'command' => 'php artisan db:seed --force',
                    'attempt' => 1,
                ],
            ],
        ]);

        $this->assertStringContainsString('git clone --', (new CloneRepositoryScript)->script(1, $build));
        $this->assertStringContainsString("'/var/www/active-website/setup/apps/storefront'", (new CloneRepositoryScript)->script(1, $build));
        $this->assertStringContainsString("cd -- '/var/www/active-website/setup/apps/storefront'", (new InstallDependenciesScript)->script(2, $build));
        $this->assertStringContainsString("cd -- '/var/www/active-website/setup/apps/storefront'", (new RunBuildCommandsScript)->script(3, $build));
        $this->assertStringContainsString("CURRENT_PATH='/var/www/active-website/setup/apps/storefront'", (new SymlinkScript)->script(4, $build));
        $this->assertStringContainsString("cd -- '/var/www/active-website/setup/apps/storefront'", (new ArtisanCommandsScript)->script(5, $build));
        $this->assertStringContainsString("CANDIDATE_PATH='/var/www/active-website/setup/apps/storefront'", (new ValidateCandidateScript)->script(6, $build));
        $this->assertStringContainsString("cd -- '/var/www/active-website/current/apps/storefront'", (new RunPostDeploymentCommandsScript)->script(7, $build));
        $this->assertStringContainsString("cd -- '/var/www/active-website/current/apps/storefront'", (new PreviewInitializationScript)->render($build));

        $processScript = (new ConfigureProcessesScript)->script(8, $build);
        $this->assertStringContainsString(
            base64_encode("#!/bin/bash\nset -e\ncd -- '/var/www/active-website/current/apps/storefront'\nexec php artisan queue:work\n"),
            $processScript,
        );

        $runtimeScript = (new ConfigureWebRuntimeScript)->script(9, $build);
        $this->assertStringContainsString('/var/www/active-website/current/apps/storefront/public', $this->decodedCaddy($runtimeScript));

        $websiteScript = (new AddWebsiteToCaddyScript)->script(1, $website);
        $this->assertStringContainsString('/var/www/active-website/current/apps/storefront/public', $this->decodedCaddy($websiteScript));

        foreach ([
            (new CloneRepositoryScript)->script(1, $build),
            (new InstallDependenciesScript)->script(2, $build),
            (new RunBuildCommandsScript)->script(3, $build),
            (new SymlinkScript)->script(4, $build),
            (new ArtisanCommandsScript)->script(5, $build),
            (new ValidateCandidateScript)->script(6, $build),
            (new RunPostDeploymentCommandsScript)->script(7, $build),
            (new ConfigureProcessesScript)->script(8, $build),
            $runtimeScript,
        ] as $script) {
            $this->assertShellSyntax($script);
        }
    }

    public function test_maintenance_jobs_use_the_latest_successful_service_root(): void
    {
        [$owner, $provider, $server, $website] = $this->infrastructure();
        $repository = $owner->repositories()->create([
            ...$this->payload($provider, $website),
            'deployment_root' => 'apps/storefront',
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id,
            'name' => 'Storefront',
            'slug' => 'storefront',
            'preset' => 'custom',
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'branch' => 'main',
            'website_id' => $website->id,
            'server_id' => $server->id,
            'minimum_replicas' => 1,
            'maximum_replicas' => 1,
            'desired_replicas' => 1,
        ]);
        $environment->builds()->create([
            'repository_id' => $repository->id,
            'status' => Build::STATUS_SUCCEEDED,
            'environment_payload' => ['repository_root' => 'apps/storefront'],
        ]);

        $snapshot = $website->runtimeLogs()->create([
            'type' => 'application',
            'status' => WebsiteLogSnapshot::STATUS_QUEUED,
        ]);
        $logCommand = '';
        (new RefreshWebsiteLogJob($website->id, 'application'))->handle($this->runner($logCommand, "healthy\n"));
        $this->assertStringContainsString('/var/www/active-website/current/apps/storefront/storage/logs/laravel.log', $logCommand);
        $this->assertSame(WebsiteLogSnapshot::STATUS_READY, $snapshot->fresh()->status);

        $runtimeCommand = '';
        (new ApplyEnvironmentRuntimeStateJob($environment->id))->handle($this->runner($runtimeCommand));
        $this->assertStringContainsString("ROOT='/var/www/active-website/current/apps/storefront'", $runtimeCommand);
    }

    public function test_unsafe_service_roots_are_rejected_without_persistence(): void
    {
        [$owner, $provider, , $website] = $this->infrastructure();

        foreach (['../secrets', '/var/www/shared', 'apps/../shared', 'apps/*'] as $root) {
            $this->actingAs($owner)->post(route('repositories.store'), [
                ...$this->payload($provider, $website),
                'deployment_root' => $root,
            ])->assertSessionHasErrors('deployment_root');
        }

        $this->assertDatabaseCount('repositories', 0);
    }

    /** @return array{User, Provider, Server, Website} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Active Website',
            'description' => 'Active website',
            'environment' => 'APP_ENV=production',
            'url' => 'active.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $provider, $server, $website];
    }

    /** @return array<string, mixed> */
    private function payload(Provider $provider, Website $website): array
    {
        return [
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ];
    }

    private function decodedCaddy(string $script): string
    {
        if (! preg_match("/printf '%s' '([^']*)' \| base64 --decode > '\/etc\/caddy/", $script, $matches)) {
            return '';
        }

        return base64_decode($matches[1], true) ?: '';
    }

    private function assertShellSyntax(string $script): void
    {
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }

    private function runner(string &$command, string $output = ''): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $process->shouldReceive('getErrorOutput')->zeroOrMoreTimes()->andReturn('');
        $process->shouldReceive('getOutput')->zeroOrMoreTimes()->andReturn($output);
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->with(Mockery::on(function (string $value) use (&$command): bool {
            $command = $value;

            return true;
        }))->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }
}
