<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\EnvironmentResource;
use App\Models\PreviewStackCleanup;
use App\Models\Project;
use App\Scripts\Repository\ConfigureResourcesScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\RunBuildCommandsScript;
use App\Services\ApplicationTemplateCatalog;
use App\Services\PreviewStackCatalog;
use App\Services\PreviewStackCleanupScript;
use App\Services\RepositoryDeploymentPlan;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use UnexpectedValueException;

class ServiceTemplateLifecycleTest extends TestCase
{
    public function test_published_templates_are_connected_to_the_existing_deployment_lifecycle(): void
    {
        $catalog = app(ApplicationTemplateCatalog::class);
        $previewStacks = app(PreviewStackCatalog::class);
        $plan = app(RepositoryDeploymentPlan::class)->scripts();

        $this->assertContains(InstallDependenciesScript::class, $plan);
        $this->assertContains(ConfigureResourcesScript::class, $plan);

        foreach ($catalog->all() as $template) {
            if ($template->serviceTemplate === null) {
                continue;
            }

            $stack = $previewStacks->for(new Project(['preset' => $template->key]));
            $declaredResources = array_column($template->serviceTemplate->resources, 'name');
            $previewResources = array_column($stack->resources, 'name');

            $this->assertSame($declaredResources, $previewResources, $template->key);
            $this->assertNotEmpty($template->serviceTemplate->readinessChecks, $template->key);

            foreach ($stack->resources as $resource) {
                $this->assertContains($resource['type'], [
                    'postgresql',
                    'valkey',
                ], $template->key);
                $this->assertContains($resource['type'], EnvironmentResource::TYPES, $template->key);
            }
        }
    }

    public function test_published_resources_render_through_install_and_exact_cleanup_paths(): void
    {
        $catalog = app(ApplicationTemplateCatalog::class);
        $previewStacks = app(PreviewStackCatalog::class);
        $cleanup = app(PreviewStackCleanupScript::class);

        foreach ($catalog->all() as $template) {
            if ($template->serviceTemplate === null) {
                continue;
            }

            $stack = $previewStacks->for(new Project(['preset' => $template->key]));
            $build = new Build([
                'environment_payload' => [
                    'resources' => array_map(fn (array $resource): array => $this->resourcePayload($resource), $stack->resources),
                ],
            ]);
            $build->id = 42;

            $resourceScript = (new ConfigureResourcesScript)->script(1, $build);
            $this->assertShellSyntax($resourceScript);

            $manifest = array_map(fn (array $resource): array => $this->cleanupManifest($resource), $stack->resources);
            $cleanupScript = $cleanup->render(new PreviewStackCleanup([
                'deployment_slug' => 'preview-app',
                'process_manifest' => [],
                'resource_manifest' => $manifest,
            ]));
            $this->assertShellSyntax($cleanupScript);
            $this->assertStringContainsString('DROP DATABASE IF EXISTS', $cleanupScript, $template->key);
            $this->assertStringContainsString("docker container inspect 'buildpusher-valkey-42-cache'", $cleanupScript, $template->key);
        }
    }

    public function test_template_upgrades_remain_in_the_reviewed_deployment_path(): void
    {
        $scripts = app(RepositoryDeploymentPlan::class)->scripts();
        $installation = array_search(InstallDependenciesScript::class, $scripts, true);
        $buildCommands = array_search(RunBuildCommandsScript::class, $scripts, true);

        $this->assertIsInt($installation);
        $this->assertIsInt($buildCommands);
        $this->assertLessThan($buildCommands, $installation);

        foreach (app(ApplicationTemplateCatalog::class)->all() as $template) {
            if ($template->serviceTemplate === null) {
                continue;
            }

            $this->assertNotEmpty($template->serviceTemplate->upgrade['policy'] ?? null, $template->key);
            $this->assertNotEmpty($template->serviceTemplate->upgrade['application'] ?? null, $template->key);
            $this->assertNotEmpty($template->serviceTemplate->upgrade['resources'] ?? null, $template->key);
        }
    }

    /** @param  array<string, mixed>  $resource */
    private function resourcePayload(array $resource): array
    {
        return match ($resource['type'] ?? null) {
            'postgresql' => [
                'name' => $resource['name'],
                'type' => 'postgresql',
                'is_managed' => true,
                'configuration' => ['variables' => [
                    'DB_HOST' => '127.0.0.1',
                    'DB_DATABASE' => 'preview_app',
                    'DB_USERNAME' => 'preview_app',
                    'DB_PASSWORD' => 'database-secret',
                ]],
            ],
            'valkey' => [
                'name' => $resource['name'],
                'type' => 'valkey',
                'is_managed' => true,
                'configuration' => [
                    'container_name' => 'buildpusher-valkey-42-cache',
                    'variables' => [
                        'VALKEY_PORT' => 6380,
                        'REDIS_PASSWORD' => 'cache-secret',
                    ],
                ],
            ],
            default => throw new UnexpectedValueException('The test fixture contains an unsupported resource type.'),
        };
    }

    /** @param  array<string, mixed>  $resource */
    private function cleanupManifest(array $resource): array
    {
        return match ($resource['type'] ?? null) {
            'postgresql' => [
                'type' => 'postgresql',
                'name' => $resource['name'],
                'status' => 'active',
                'database' => 'preview_app',
                'username' => 'preview_app',
            ],
            'valkey' => [
                'type' => 'valkey',
                'name' => $resource['name'],
                'status' => 'active',
                'container_name' => 'buildpusher-valkey-42-cache',
                'volume_name' => 'buildpusher-valkey-42-cache-data',
            ],
            default => throw new UnexpectedValueException('The test fixture contains an unsupported resource type.'),
        };
    }

    private function assertShellSyntax(string $script): void
    {
        $syntaxCheck = new Process(['bash', '-n']);
        $syntaxCheck->setInput($script);
        $syntaxCheck->run();

        $this->assertTrue($syntaxCheck->isSuccessful(), $syntaxCheck->getErrorOutput());
    }
}
