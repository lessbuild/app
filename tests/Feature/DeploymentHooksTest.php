<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\RunBuildCommandsScript;
use App\Scripts\Repository\RunPostDeploymentCommandsScript;
use App\Scripts\Repository\VerifyDeploymentHealthScript;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentHooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_store_encrypted_hooks_and_edit_them_again(): void
    {
        [$owner, $provider, $website] = $this->infrastructure();
        $buildCommands = "npm run build\nprintf '%s\\n' build-complete";
        $postCommands = "php artisan queue:restart\nprintf '%s\\n' post-complete";

        $this->actingAs($owner)->post(route('repositories.store'), [
            ...$this->payload($provider, $website),
            'build_commands' => $buildCommands,
            'post_deployment_commands' => $postCommands,
        ])->assertRedirect();

        $repository = Repository::query()->sole();
        $this->assertSame($buildCommands, $repository->build_commands);
        $this->assertSame($postCommands, $repository->post_deployment_commands);
        $raw = Repository::query()->toBase()->find($repository->id);
        $this->assertNotSame($buildCommands, $raw->build_commands);
        $this->assertNotSame($postCommands, $raw->post_deployment_commands);
        $this->assertStringNotContainsString('npm run build', $raw->build_commands);
        $this->assertStringNotContainsString('queue:restart', $raw->post_deployment_commands);
        $this->assertArrayNotHasKey('build_commands', $repository->toArray());
        $this->assertArrayNotHasKey('post_deployment_commands', $repository->toArray());

        $this->actingAs($owner)->get(route('repositories.edit', $repository))
            ->assertSuccessful()
            ->assertSeeText($buildCommands)
            ->assertSeeText($postCommands)
            ->assertSee('Do not place secrets directly in these fields');
        $this->actingAs($owner)->get(route('repositories.show', $repository))
            ->assertSuccessful()
            ->assertSee('Build hook configured')
            ->assertSee('Post-deployment hook configured')
            ->assertDontSee($buildCommands);
    }

    public function test_blank_hooks_are_normalized_and_omitted_fields_preserve_existing_hooks(): void
    {
        [$owner, $provider, $website] = $this->infrastructure();
        $repository = $owner->repositories()->create([
            ...$this->payload($provider, $website),
            'build_commands' => 'npm run production',
            'post_deployment_commands' => 'php artisan queue:restart',
        ]);

        $this->actingAs($owner)->patch(route('repositories.update', $repository), $this->payload($provider, $website))
            ->assertRedirect(route('repositories.show', $repository));
        $this->assertSame('npm run production', $repository->fresh()->build_commands);
        $this->assertSame('php artisan queue:restart', $repository->fresh()->post_deployment_commands);

        $this->actingAs($owner)->patch(route('repositories.update', $repository), [
            ...$this->payload($provider, $website),
            'build_commands' => "  \n",
            'post_deployment_commands' => '',
        ])->assertRedirect(route('repositories.show', $repository));
        $this->assertNull($repository->fresh()->build_commands);
        $this->assertNull($repository->fresh()->post_deployment_commands);
    }

    public function test_hooks_are_bounded_before_they_are_encrypted(): void
    {
        [$owner, $provider, $website] = $this->infrastructure();

        $this->actingAs($owner)->post(route('repositories.store'), [
            ...$this->payload($provider, $website),
            'build_commands' => str_repeat('x', 10001),
            'post_deployment_commands' => str_repeat('y', 10001),
        ])->assertSessionHasErrors(['build_commands', 'post_deployment_commands']);

        $this->assertDatabaseCount('repositories', 0);
    }

    public function test_hooks_run_from_encoded_memory_in_the_correct_pipeline_phases(): void
    {
        [$owner, $provider, $website] = $this->infrastructure();
        $repository = $owner->repositories()->create([
            ...$this->payload($provider, $website),
            'build_commands' => 'printf \'%s\n\' "build; $APP_KEY"',
            'post_deployment_commands' => 'printf \'%s\n\' "post; $DB_PASSWORD"',
        ]);
        $build = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);

        $buildScript = (new RunBuildCommandsScript)->script(4, $build);
        $postScript = (new RunPostDeploymentCommandsScript)->script(8, $build);
        $this->assertHookScript(
            $buildScript,
            $repository->build_commands,
            '/var/www/application/setup',
            'Custom build commands failed',
        );
        $this->assertHookScript(
            $postScript,
            $repository->post_deployment_commands,
            '/var/www/application/current',
            'Post-deployment commands failed',
        );

        $scripts = app(RepositoryDeploymentPlan::class)->scripts();
        $this->assertLessThan(
            array_search(RunBuildCommandsScript::class, $scripts, true),
            array_search(InstallDependenciesScript::class, $scripts, true),
        );
        $this->assertLessThan(
            array_search(ActivateReleaseScript::class, $scripts, true),
            array_search(RunBuildCommandsScript::class, $scripts, true),
        );
        $this->assertLessThan(
            array_search(RunPostDeploymentCommandsScript::class, $scripts, true),
            array_search(ArtisanCommandsScript::class, $scripts, true),
        );
        $this->assertLessThan(
            array_search(VerifyDeploymentHealthScript::class, $scripts, true),
            array_search(RunPostDeploymentCommandsScript::class, $scripts, true),
        );
    }

    public function test_unconfigured_hooks_remain_visible_progress_steps_without_shell_execution(): void
    {
        [$owner, $provider, $website] = $this->infrastructure();
        $repository = $owner->repositories()->create($this->payload($provider, $website));
        $build = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);

        $buildScript = (new RunBuildCommandsScript)->script(4, $build);
        $postScript = (new RunPostDeploymentCommandsScript)->script(8, $build);
        $this->assertStringContainsString('Custom build commands disabled', $buildScript);
        $this->assertStringContainsString('Post-deployment commands disabled', $postScript);
        $this->assertStringNotContainsString('bash -Eeuo pipefail', $buildScript.$postScript);
        $this->assertStringNotContainsString('base64 --decode', $buildScript.$postScript);
        $this->assertShellSyntax($buildScript);
        $this->assertShellSyntax($postScript);
    }

    private function assertHookScript(
        string $script,
        string $commands,
        string $workingDirectory,
        string $failureMessage,
    ): void {
        $this->assertStringNotContainsString($commands, $script);
        $this->assertStringContainsString(base64_encode($commands), $script);
        $this->assertStringContainsString("cd -- '{$workingDirectory}'", $script);
        $this->assertStringContainsString("bash -Eeuo pipefail <(printf '%s'", $script);
        $this->assertStringContainsString('| base64 --decode)', $script);
        $this->assertStringContainsString("DEPLOYMENT_FAILURE_MESSAGE='{$failureMessage}'", $script);
        $this->assertStringNotContainsString('eval ', $script);
        $this->assertStringNotContainsString('HOOK_FILE', $script);
        $this->assertShellSyntax($script);
    }

    /** @return array{User, Provider, Website} */
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
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-root-secret',
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'deployment_slug' => 'application',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $provider, $website];
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

    private function assertShellSyntax(string $script): void
    {
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }
}
