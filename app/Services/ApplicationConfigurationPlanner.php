<?php

namespace App\Services;

use App\Models\ConfigurationOwnership;
use App\Models\EnvironmentVariable;
use App\Models\PreviewDeployment;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Validation\ValidationException;

class ApplicationConfigurationPlanner
{
    public function __construct(
        private readonly ApplicationConfigurationDocument $documents,
        private readonly ApplicationConfigurationBindings $bindings,
        private readonly Entitlements $entitlements,
        private readonly ApplicationConfigurationRemovalPlan $removals,
    ) {}

    /** A preview only; this result is not an authorization token for applying changes. */
    public function plan(Project $project, User $user, string $yaml, array $bindings): array
    {
        $document = $this->documents->parse($yaml);
        $resolved = $this->bindings->resolve($project, $user, $document, $bindings);
        $environments = $project->environments()->with(['processes', 'resources', 'variables'])->get()->keyBy('slug');
        $resultingTypes = $environments->map(fn ($environment) => $environment->type)->all();
        foreach ($document['environments'] as $slug => $desired) {
            $resultingTypes[$slug] = $desired['type'];
        }
        if (count(array_filter($resultingTypes, fn ($type) => $type === 'production')) > 1) {
            throw ValidationException::withMessages(['plan' => 'An application may have only one production environment.']);
        }
        $ownerships = ConfigurationOwnership::query()->where('project_id', $project->id)->orderBy('id')->get();
        $action = function (string $slug, string $kind, string $name, $current, bool $adopt) use ($ownerships): string {
            $ownership = $ownerships->first(fn ($record) => $record->environment_slug === $slug && $record->kind === $kind && $record->logical_name === $name);
            if ($ownership && (! $current || (int) $ownership->resource_id !== (int) $current->id)) {
                throw ValidationException::withMessages(['plan' => 'Configuration ownership no longer matches the target. Resolve ownership before reviewing changes.']);
            }
            if ($current && ConfigurationOwnership::query()->where('kind', $kind)->where('resource_id', $current->id)
                ->when($ownership, fn ($query) => $query->where('id', '!=', $ownership->id))->exists()) {
                throw ValidationException::withMessages(['plan' => 'The target is already managed by another configuration identity.']);
            }

            return $ownership ? 'update' : ($current ? ($adopt ? 'adopt' : 'adoption_required') : 'create');
        };
        $changes = [];
        foreach ($document['remove']['environments'] ?? [] as $slug) {
            array_push($changes, ...$this->removals->plan($project, $slug, $environments->get($slug), $action));
        }
        foreach ($document['environments'] as $slug => $desired) {
            foreach (['processes' => 'workers', 'resources' => 'resources'] as $section => $feature) {
                if (! empty($desired[$section]) && ! $this->entitlements->allows($project->organization, $feature)) {
                    // Planning remains read-only, including denial telemetry.
                    throw ValidationException::withMessages(['plan' => 'The workspace plan does not include a requested capability.']);
                }
            }
            $existing = $environments->get($slug);
            if ($existing && PreviewDeployment::query()->where('environment_id', $existing->id)
                ->where(fn ($query) => $query->where('status', '!=', PreviewDeployment::STATUS_CLOSED)->orWhereNull('closed_at'))->exists()) {
                throw ValidationException::withMessages(['plan' => 'Close the active preview through its lifecycle before managing its environment with configuration.']);
            }
            $managedValkey = $existing?->resources->where('type', 'valkey')->where('is_managed', true)->pluck('name')->all() ?? [];
            $managedValkey = array_diff($managedValkey, $desired['remove']['resources'] ?? []);
            foreach ($desired['resources'] ?? [] as $name => $resource) {
                if ($resource['managed'] && $resource['type'] === 'valkey') {
                    $managedValkey[] = $name;
                }
            }
            if (count(array_unique($managedValkey)) > 1) {
                throw ValidationException::withMessages(['plan' => 'An environment supports one managed Valkey resource. Detach the existing resource before attaching a replacement.']);
            }
            if (isset($desired['deploy'])) {
                $changes[] = ['environment' => $slug, 'kind' => 'deployment', 'name' => $desired['deploy']['repository'],
                    'action' => 'deploy', 'fields' => [], 'requires_approval' => (bool) $existing?->requires_deployment_approval];
            }
            $placement = $resolved['placements'][$desired['placement']];
            if (Website::query()->findOrFail($placement['website_id'])->hasActiveDeployment()
                || ($existing?->website_id && Website::query()->find($existing->website_id)?->hasActiveDeployment())) {
                throw ValidationException::withMessages(['plan' => 'Wait for the active deployment to finish before reviewing configuration.']);
            }
            $attributes = [
                'type' => $desired['type'],
                'website_id' => $placement['website_id'],
                'server_id' => $placement['server_id'],
                'runtime_type' => $desired['runtime']['type'],
                'build_command' => $desired['runtime']['build_command'] ?? null,
                'start_command' => $desired['runtime']['start_command'] ?? null,
                'container_port' => $desired['runtime']['port'] ?? null,
                'dockerfile_path' => $desired['runtime']['dockerfile_path'] ?? null,
            ];
            $fields = array_keys(array_filter($attributes, fn ($value, $key) => $existing === null || $existing->{$key} !== $value, ARRAY_FILTER_USE_BOTH));
            $changes[] = ['environment' => $slug, 'kind' => 'environment', 'name' => $slug,
                'action' => $action($slug, 'environment', $slug, $existing, $desired['adopt'] ?? false), 'fields' => $fields];

            foreach (['processes', 'resources', 'variables'] as $kind) {
                foreach ($desired[$kind] ?? [] as $name => $settings) {
                    $current = $existing?->{$kind}->firstWhere($kind === 'variables' ? 'key' : 'name', $name);
                    if ($kind === 'resources' && $current
                        && ($current->type !== $settings['type'] || $current->is_managed !== $settings['managed'])) {
                        throw ValidationException::withMessages(['plan' => 'Changing a resource type or management mode requires an explicit detachment review first. Remote data is not migrated automatically.']);
                    }
                    $changes[] = ['environment' => $slug, 'kind' => $kind, 'name' => $name,
                        'action' => $action($slug, $kind, $name, $current, $settings['adopt'] ?? false),
                        'fields' => array_values(array_diff(array_keys($settings), ['adopt']))];
                }
            }
            foreach ($desired['remove'] ?? [] as $kind => $names) {
                foreach ($names as $name) {
                    $current = $existing?->{$kind}->firstWhere($kind === 'variables' ? 'key' : 'name', $name);
                    $ownershipAction = $action($slug, $kind, $name, $current, false);
                    if ($current && $ownershipAction !== 'update') {
                        throw ValidationException::withMessages(['plan' => 'Only configuration-owned objects can be removed. Adopt the object in a separate review first.']);
                    }
                    $changes[] = ['environment' => $slug, 'kind' => $kind, 'name' => $name,
                        'action' => $current ? ($kind === 'resources' ? 'detach' : 'remove') : 'absent',
                        'fields' => [], 'remote_data_deleted' => false];
                }
            }
        }

        $state = $environments->sortKeys()->map(function ($environment): array {
            $record = $environment->getRawOriginal();
            foreach (['processes', 'resources', 'variables'] as $relation) {
                $record[$relation] = $environment->{$relation}->sortBy('id')->map(fn ($model) => $model->getRawOriginal())->values()->all();
            }

            return $record;
        })->all();
        // Key the digest so low-entropy values cannot be guessed from a public hash.
        $ownershipState = $ownerships->map(fn ($record) => $record->getRawOriginal())->all();
        // Sources may live in another project and are not part of the target state.
        // Include raw encrypted records without decrypting or returning their contents.
        $sourceState = EnvironmentVariable::query()->whereIn('id', array_column($resolved['secrets'], 'variable_id'))
            ->orderBy('id')->get()->map(fn ($record) => $record->getRawOriginal())->all();
        $fingerprint = hash_hmac('sha256', json_encode([$document, $resolved, $state, $ownershipState, $sourceState], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return ['version' => 2, 'project_id' => $project->id, 'changes' => $changes, 'fingerprint' => $fingerprint,
            'omitted_objects' => 'preserved', 'apply_available' => ! collect($changes)->contains('action', 'adoption_required')];
    }
}
