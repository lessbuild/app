<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\PurgeOldReleasesScript;
use App\Scripts\Repository\SymlinkScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReleaseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_script_generation_does_not_create_phantom_builds(): void
    {
        $repository = $this->repository();
        $build = $repository->builds()->create(['status' => Build::STATUS_QUEUED]);

        $script = (new ActivateReleaseScript)->script(4, $build);

        $this->assertDatabaseCount('builds', 1);
        $this->assertStringContainsString('if [ -d "$CURRENT_PATH" ] && [ ! -L "$CURRENT_PATH" ]', $script);
        $this->assertStringContainsString('mv -Tf -- "$NEXT_LINK" "$CURRENT_PATH"', $script);
        $this->assertShellSyntax($script);
    }

    public function test_dependency_installation_is_lockfile_driven_and_does_not_update_dependencies(): void
    {
        $script = (new InstallDependenciesScript)->script(3, $this->build());

        $this->assertStringContainsString('composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader', $script);
        $this->assertStringNotContainsString('composer update', $script);
        $this->assertStringContainsString('npm ci --no-audit --no-fund', $script);
        $this->assertStringContainsString('npm run build --if-present', $script);
        $this->assertShellSyntax($script);
    }

    public function test_shared_files_use_persistent_storage_without_world_writable_permissions(): void
    {
        $script = (new SymlinkScript)->script(5, $this->build());

        $this->assertStringContainsString('SHARED_STORAGE="$DEPLOY_ROOT/shared/storage"', $script);
        $this->assertStringContainsString('ln -sfn -- "$SHARED_STORAGE" "$CURRENT_PATH/storage"', $script);
        $this->assertStringContainsString('ln -sfn -- "$DEPLOY_ROOT/.env" "$CURRENT_PATH/.env"', $script);
        $this->assertStringContainsString('chmod 775', $script);
        $this->assertStringNotContainsString('chmod -R 777', $script);
        $this->assertShellSyntax($script);
    }

    public function test_optional_artisan_services_and_old_release_retention_are_safe(): void
    {
        $build = $this->build();
        $artisan = (new ArtisanCommandsScript)->script(6, $build);
        $purge = (new PurgeOldReleasesScript)->script(7, $build);

        $this->assertStringContainsString("grep -qx 'horizon:terminate'", $artisan);
        $this->assertStringContainsString('tail -n +6', $purge);
        $this->assertStringContainsString('rm -rf -- "$release"', $purge);
        $this->assertShellSyntax($artisan);
        $this->assertShellSyntax($purge);
    }

    private function repository(): Repository
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'github-secret',
            'description' => 'Source provider',
        ]);
        $server = $user->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Customer Portal',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'portal.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);
    }

    private function build(): Build
    {
        return $this->repository()->builds()->create(['status' => Build::STATUS_RUNNING]);
    }

    private function assertShellSyntax(string $script): void
    {
        $syntaxCheck = new Process(['bash', '-n']);
        $syntaxCheck->setInput($script);
        $syntaxCheck->run();

        $this->assertTrue($syntaxCheck->isSuccessful(), $syntaxCheck->getErrorOutput());
    }
}
