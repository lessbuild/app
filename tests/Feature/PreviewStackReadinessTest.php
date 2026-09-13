<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\PreviewStackReadiness;
use App\Services\ProvisioningCallbackUrl;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewStackReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_stage_marks_only_preview_stack_resources_ready(): void
    {
        [$environment, $build] = $this->previewBuild();
        $manual = $environment->resources()->create([
            'name' => 'manual-cache',
            'type' => 'redis',
            'is_managed' => true,
            'status' => EnvironmentResource::STATUS_READY,
        ]);
        $this->app->make(PreviewStackReadiness::class)->beginProvisioning($build);

        $this->assertSame(EnvironmentResource::STATUS_PROVISIONING, $environment->resources()->where('name', 'database')->value('status'));
        $this->assertSame(EnvironmentResource::STATUS_PROVISIONING, $environment->resources()->where('name', 'cache')->value('status'));
        $this->assertSame(EnvironmentResource::STATUS_READY, $manual->fresh()->status);

        $build->update(['status' => Build::STATUS_RUNNING]);
        $this->post(ProvisioningCallbackUrl::buildStatus($build), [
            'status' => app(RepositoryDeploymentPlan::class)->resourceStage(),
        ])->assertNoContent();

        $this->assertSame(EnvironmentResource::STATUS_READY, $environment->resources()->where('name', 'database')->value('status'));
        $this->assertSame(EnvironmentResource::STATUS_READY, $environment->resources()->where('name', 'cache')->value('status'));
        $this->assertSame(EnvironmentResource::STATUS_READY, $manual->fresh()->status);
    }

    public function test_failed_preview_build_marks_initializing_resources_failed_but_preserves_ready_resources(): void
    {
        [$environment, $build] = $this->previewBuild();
        $ready = $environment->resources()->where('name', 'database')->firstOrFail();
        $ready->update(['status' => EnvironmentResource::STATUS_READY]);
        $this->app->make(PreviewStackReadiness::class)->beginProvisioning($build);

        $this->post(ProvisioningCallbackUrl::buildFailure($build), [
            'message' => 'Preview deployment failed',
        ])->assertNoContent();

        $this->assertSame(EnvironmentResource::STATUS_READY, $ready->fresh()->status);
        $this->assertSame(EnvironmentResource::STATUS_FAILED, $environment->resources()->where('name', 'cache')->value('status'));
    }

    public function test_non_preview_builds_do_not_change_resource_status(): void
    {
        [$environment, $build] = $this->previewBuild(type: 'staging');
        $this->app->make(PreviewStackReadiness::class)->beginProvisioning($build);

        $this->assertSame(EnvironmentResource::STATUS_PLANNED, $environment->resources()->where('name', 'database')->value('status'));
    }

    public function test_terminal_build_callbacks_do_not_change_preview_resource_status(): void
    {
        [$environment, $build] = $this->previewBuild();
        $this->app->make(PreviewStackReadiness::class)->beginProvisioning($build);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);

        $this->post(ProvisioningCallbackUrl::buildStatus($build), [
            'status' => app(RepositoryDeploymentPlan::class)->resourceStage(),
        ])->assertNoContent();

        $this->assertSame(EnvironmentResource::STATUS_PROVISIONING, $environment->resources()->where('name', 'database')->value('status'));
    }

    /** @return array{Environment, Build} */
    private function previewBuild(string $type = 'preview'): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'GitHub', 'provider' => Provider::TYPE_GITHUB, 'token' => 'token', 'description' => 'Source',
        ]);
        $server = $user->servers()->create([
            'name' => 'Preview server', 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id, 'name' => 'Preview', 'description' => 'Preview',
            'environment' => '', 'url' => 'preview.example.com', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $user->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Preview',
            'url' => 'github.com/example/preview.git', 'branch' => 'main', 'description' => 'Preview',
        ]);
        $project = $user->currentOrganization->projects()->create([
            'created_by' => $user->id, 'name' => 'Preview', 'slug' => 'preview', 'preset' => 'laravel',
        ]);
        $environment = $project->environments()->create([
            'server_id' => $server->id, 'website_id' => $website->id, 'name' => ucfirst($type),
            'slug' => $type, 'type' => $type, 'branch' => 'main',
        ]);
        $environment->resources()->createMany([
            ['name' => 'database', 'type' => 'postgresql', 'is_managed' => true, 'status' => EnvironmentResource::STATUS_PLANNED, 'configuration' => ['variables' => []]],
            ['name' => 'cache', 'type' => 'valkey', 'is_managed' => true, 'status' => EnvironmentResource::STATUS_PLANNED, 'configuration' => ['variables' => []]],
        ]);
        $build = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_RUNNING,
            'environment_payload' => ['resources' => []],
        ]);

        return [$environment, $build];
    }
}
