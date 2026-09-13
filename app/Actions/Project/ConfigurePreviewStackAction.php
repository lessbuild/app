<?php

namespace App\Actions\Project;

use App\Actions\Environment\SaveEnvironmentProcessAction;
use App\Actions\Environment\SaveEnvironmentResourceAction;
use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\Project;
use App\Services\Entitlements;
use App\Services\PreviewResourceCredentials;
use App\Services\PreviewStackCatalog;
use InvalidArgumentException;

class ConfigurePreviewStackAction
{
    /**
     * Bind the curated stack definition, environment persistence actions and plan checks.
     *
     * @param  PreviewStackCatalog  $catalog  Resolves only explicitly supported template declarations.
     * @param  Entitlements  $entitlements  Prevents preview children from bypassing workspace capabilities.
     * @param  SaveEnvironmentProcessAction  $processes  Reuses the normal process persistence behavior.
     * @param  SaveEnvironmentResourceAction  $resources  Reuses managed-resource configuration and encryption.
     * @param  PreviewResourceCredentials  $credentials  Generates or preserves preview-only Valkey credentials.
     */
    public function __construct(
        private readonly PreviewStackCatalog $catalog,
        private readonly Entitlements $entitlements,
        private readonly SaveEnvironmentProcessAction $processes,
        private readonly SaveEnvironmentResourceAction $resources,
        private readonly PreviewResourceCredentials $credentials,
    ) {}

    /**
     * Persist the supported preview children while the caller's preview transaction is open.
     *
     * The preview website already owns a fresh encrypted database password. The normal
     * resource action derives PostgreSQL variables from that password; no source
     * website environment or secret variable is copied. Remote readiness is left as
     * planned until the preview provisioning lifecycle records it explicitly.
     *
     * @param  Project  $project  The project and organization whose preset is being previewed.
     * @param  Environment  $environment  The preview environment receiving the stack.
     * @return void No value; declarations are idempotently updated by name.
     */
    public function handle(Project $project, Environment $environment): void
    {
        if ($environment->type !== 'preview' || $environment->project_id !== $project->id) {
            throw new InvalidArgumentException('Preview stack configuration requires a preview environment owned by the project.');
        }

        $stack = $this->catalog->for($project);

        if ($this->entitlements->allows($project->organization, 'workers')) {
            foreach ($stack->processes as $process) {
                $this->processes->handle($environment, [
                    'name' => $process['name'],
                    'type' => $process['type'],
                    'command' => $process['command'],
                    'replicas' => 1,
                    'restart_policy' => 'always',
                    'restart_delay_seconds' => 5,
                    'is_enabled' => true,
                    'is_preview_owned' => true,
                ]);
            }
        }

        if ($this->entitlements->allows($project->organization, 'resources')) {
            foreach ($stack->resources as $resource) {
                $existing = $environment->resources()->where('name', $resource['name'])->first();
                $managedVariables = $resource['type'] === 'valkey'
                    ? $this->credentials->valkey($existing)
                    : null;
                $this->resources->handle($environment, [
                    'name' => $resource['name'],
                    'type' => $resource['type'],
                    'is_managed' => $resource['is_managed'],
                    'status' => $existing?->status ?? EnvironmentResource::STATUS_PLANNED,
                    'is_preview_owned' => true,
                ], null, $managedVariables);
            }
        }
    }
}
