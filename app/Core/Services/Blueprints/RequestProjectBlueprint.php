<?php

namespace App\Core\Services\Blueprints;

use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectBlueprintRun;
use App\Core\Models\ProjectBlueprintStep;
use App\Core\Models\ProjectBlueprintVersion;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RequestProjectBlueprint
{
    public function __construct(private readonly PreviewProjectBlueprint $previews, private readonly BlueprintAuthority $authority, private readonly WorkspaceProjectAccess $access) {}

    public function handle(PlatformUser $actor, Workspace $workspace, Project $project, ProjectBlueprintVersion $version, array $selections, string $previewToken, string $idempotencyKey): ProjectBlueprintRun
    {
        abort_unless(Str::isUuid($idempotencyKey), 422);
        $project = Project::query()->where('workspace_id', $workspace->getKey())->findOrFail($project->getKey());
        abort_unless($this->access->canManageWorkspace($actor, $workspace) && $this->access->canViewProject($actor, $project), 403);
        $version = ProjectBlueprintVersion::query()->whereHas('blueprint', fn ($query) => $query->where('workspace_id', $workspace->getKey()))->findOrFail($version->getKey());
        $intentHash = BlueprintFingerprint::make(['version' => (string) $version->getKey(), 'definition' => $version->definition_hash, 'project' => (string) $project->getKey(), 'environments' => $selections]);
        $existing = $this->existing($actor, $workspace, $idempotencyKey);
        if ($existing !== null) {
            return $this->matchingRun($existing, $intentHash);
        }
        $preview = $this->previews->handle($actor, $workspace, $project, $version, $selections);
        $this->previews->verifyToken($previewToken, $preview, (string) $version->getKey(), $idempotencyKey);
        if (! $preview->ready()) {
            throw ValidationException::withMessages(['preview' => __('Resolve the app blockers and review a fresh preview before applying.')]);
        }

        return DB::connection('core')->transaction(function () use ($actor, $workspace, $project, $version, $idempotencyKey, $intentHash, $preview): ProjectBlueprintRun {
            foreach (['users' => $actor->getKey(), 'workspaces' => $workspace->getKey(), 'projects' => $project->getKey()] as $table => $id) {
                DB::connection('core')->table($table)->where('id', $id)->update(['id' => DB::raw('id')]);
                DB::connection('core')->table($table)->where('id', $id)->lockForUpdate()->first();
            }
            $existing = $this->existing($actor, $workspace, $idempotencyKey);
            if ($existing !== null) {
                return $this->matchingRun($existing, $intentHash);
            }
            $version = ProjectBlueprintVersion::query()->whereHas('blueprint', fn ($query) => $query
                ->where('workspace_id', $workspace->getKey())->whereNull('archived_at'))
                ->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            if (! hash_equals($version->definition_hash, BlueprintFingerprint::make($version->definition))) {
                throw new BlueprintBlocked('intent_changed');
            }
            // Another in-flight application cannot race this project's resource bindings.
            if (ProjectBlueprintRun::query()->where('project_id', $project->getKey())->whereIn('status', ['pending', 'processing', 'blocked'])->exists()) {
                throw ValidationException::withMessages(['project' => __('Resume or reconcile the existing blueprint application before starting another for this project.')]);
            }
            foreach ($preview->products as $product => $productPreview) {
                $this->authority->authorize($preview->target, $product);
                if (! hash_equals($preview->coreBindings[$product], $this->authority->bindingHash($preview->target, $product))) {
                    throw new BlueprintBlocked('resource_bindings_changed');
                }
            }
            $environments = $preview->target->environments;
            foreach ($environments as $key => &$environment) {
                if ($environment['id'] !== null) {
                    continue;
                }
                if (ProjectEnvironment::query()->where('project_id', $project->getKey())->where('slug', $key)->exists()) {
                    throw new BlueprintBlocked('environment_changed');
                }
                $created = ProjectEnvironment::query()->create([
                    'project_id' => $project->getKey(), 'created_by_user_id' => $actor->getKey(),
                    'name' => $environment['name'], 'slug' => $key, 'environment_type' => $environment['type'], 'status' => 'active',
                ]);
                $environment['id'] = (string) $created->getKey();
            }
            unset($environment);
            $target = new BlueprintTarget((string) $actor->getKey(), (string) $workspace->getKey(), (string) $project->getKey(), $environments);
            $run = ProjectBlueprintRun::query()->create([
                'workspace_id' => $workspace->getKey(), 'project_id' => $project->getKey(), 'project_blueprint_version_id' => $version->getKey(),
                'requested_by_user_id' => $actor->getKey(), 'idempotency_key' => $idempotencyKey, 'intent_hash' => $intentHash,
                'environment_bindings' => $environments, 'status' => 'pending',
            ]);
            foreach ($version->definition['products'] as $product => $configuration) {
                $stepId = (string) Str::ulid();
                $authority = $preview->products[$product]->authority;
                ProjectBlueprintStep::query()->create([
                    'id' => $stepId, 'project_blueprint_run_id' => $run->getKey(), 'product' => $product,
                    'target' => $target->toArray(), 'configuration' => $configuration, 'native_authority' => $authority,
                    'core_binding_hash' => $this->authority->bindingHash($target, $product),
                    'payload_hash' => BlueprintFingerprint::make(['step' => $stepId, 'target' => $target->toArray(), 'configuration' => $configuration, 'authority' => $authority]),
                    'status' => 'pending', 'available_at' => now(),
                ]);
            }

            return $run;
        });
    }

    private function existing(PlatformUser $actor, Workspace $workspace, string $key): ?ProjectBlueprintRun
    {
        return ProjectBlueprintRun::query()->where('workspace_id', $workspace->getKey())->where('requested_by_user_id', $actor->getKey())->where('idempotency_key', $key)->first();
    }

    private function matchingRun(ProjectBlueprintRun $run, string $hash): ProjectBlueprintRun
    {
        if (! hash_equals($run->intent_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => __('This confirmation already belongs to another blueprint application.')]);
        }

        return $run;
    }
}
