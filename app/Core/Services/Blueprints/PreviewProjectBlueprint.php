<?php

namespace App\Core\Services\Blueprints;

use App\Core\Data\Blueprints\BlueprintPreview;
use App\Core\Data\Blueprints\BlueprintProductPreview;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectBlueprintVersion;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PreviewProjectBlueprint
{
    public function __construct(private readonly ProjectBlueprintProviderRegistry $providers, private readonly BlueprintAuthority $authority, private readonly WorkspaceProjectAccess $access) {}

    /** @param array<string, ?string> $selections Explicit canonical environment IDs, or null to create. */
    public function handle(PlatformUser $actor, Workspace $workspace, Project $project, ProjectBlueprintVersion $version, array $selections): BlueprintPreview
    {
        $project = Project::query()->where('workspace_id', $workspace->getKey())->findOrFail($project->getKey());
        $version = ProjectBlueprintVersion::query()->whereHas('blueprint', fn ($query) => $query->where('workspace_id', $workspace->getKey())->whereNull('archived_at'))->findOrFail($version->getKey());
        abort_unless($this->access->canManageWorkspace($actor, $workspace) && $this->access->canViewProject($actor, $project), 403);
        if (! hash_equals($version->definition_hash, BlueprintFingerprint::make($version->definition))) {
            throw new BlueprintBlocked('intent_changed');
        }
        $environments = $this->environments($project, $version->definition['environments'], $selections);
        $target = new BlueprintTarget((string) $actor->getKey(), (string) $workspace->getKey(), (string) $project->getKey(), $environments);
        $previews = [];
        $fingerprints = [];
        $coreBindings = [];
        foreach ($version->definition['products'] as $product => $configuration) {
            try {
                $this->authority->authorize($target, $product);
                $provider = $this->providers->get($product);
                if ($provider === null) {
                    throw new BlueprintBlocked('product_unavailable');
                }
                $previews[$product] = $provider->preview($target, $configuration);
                $coreBindings[$product] = $this->authority->bindingHash($target, $product);
                $fingerprints[$product] = [
                    'bindings' => $coreBindings[$product],
                    'native' => $previews[$product]->authority, 'plan' => $previews[$product]->planImpact,
                    'changes' => $previews[$product]->changes, 'requirements' => $previews[$product]->requirements,
                    'blockers' => $previews[$product]->blockers,
                ];
            } catch (BlueprintBlocked $exception) {
                $previews[$product] = new BlueprintProductPreview(blockers: [BlueprintMessages::reason($exception->reason)]);
            } catch (Throwable) {
                $previews[$product] = new BlueprintProductPreview(blockers: [BlueprintMessages::reason('source_unavailable')]);
            }
        }

        return new BlueprintPreview($target, $previews, BlueprintFingerprint::make([
            'version' => (string) $version->getKey(), 'definition' => $version->definition_hash, 'target' => $target->toArray(), 'products' => $fingerprints,
        ]), $coreBindings);
    }

    public function token(BlueprintPreview $preview, string $versionId, string $idempotencyKey): string
    {
        return Crypt::encryptString(json_encode([
            'actor' => $preview->target->actorId, 'workspace' => $preview->target->workspaceId, 'project' => $preview->target->projectId,
            'version' => $versionId, 'idempotency_key' => $idempotencyKey, 'fingerprint' => $preview->fingerprint, 'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function verifyToken(string $token, BlueprintPreview $preview, string $versionId, string $idempotencyKey): void
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $data = null;
        }
        if (! is_array($data) || ($data['actor'] ?? null) !== $preview->target->actorId || ($data['workspace'] ?? null) !== $preview->target->workspaceId
            || ($data['project'] ?? null) !== $preview->target->projectId || ($data['version'] ?? null) !== $versionId
            || ($data['idempotency_key'] ?? null) !== $idempotencyKey || ! is_int($data['expires_at'] ?? null) || $data['expires_at'] <= now()->timestamp
            || ! hash_equals($preview->fingerprint, (string) ($data['fingerprint'] ?? ''))) {
            throw ValidationException::withMessages(['preview' => __('The preview changed or expired. Review a fresh preview before applying.')]);
        }
    }

    private function environments(Project $project, array $definitions, array $selections): array
    {
        $keys = array_column($definitions, 'key');
        if (array_diff(array_keys($selections), $keys) !== [] || count($selections) !== count($keys)) {
            throw ValidationException::withMessages(['environments' => __('Choose an environment binding for every blueprint environment.')]);
        }
        $bindings = [];
        $used = [];
        foreach ($definitions as $definition) {
            $selected = $selections[$definition['key']];
            $environment = null;
            if ($selected !== null) {
                if (! is_string($selected) || ! Str::isUlid($selected) || isset($used[$selected])) {
                    throw ValidationException::withMessages(['environments' => __('Choose distinct active environments from this project.')]);
                }
                $environment = ProjectEnvironment::query()->whereKey($selected)->where('project_id', $project->getKey())
                    ->where('status', 'active')->where('environment_type', $definition['type'])->first();
                if ($environment === null) {
                    throw ValidationException::withMessages(['environments' => __('A selected environment is unavailable or has a different type.')]);
                }
                $used[$selected] = true;
            } elseif (ProjectEnvironment::query()->where('project_id', $project->getKey())->where('slug', $definition['key'])->exists()) {
                throw ValidationException::withMessages(['environments' => __('An environment with this key already exists. Select it explicitly or choose another blueprint key.')]);
            }
            $bindings[$definition['key']] = ['id' => $environment === null ? null : (string) $environment->getKey(),
                'name' => $environment?->name ?? $definition['name'], 'type' => $definition['type']];
        }

        return $bindings;
    }
}
