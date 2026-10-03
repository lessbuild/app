<?php

declare(strict_types=1);

namespace App\Services\Deploy\Configuration;

use App\Enums\EnvironmentKind;
use App\Models\Build;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationOwnership;
use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Compares a document with the project (ported from Deployer) and lists the changes applying it would make: create,
 * update (already owned), adopt (exists, and the document says `adopt: true`), adoption_required (exists, not owned),
 * remove/detach, absent, and deploys. Planning never writes. Objects the document omits are left alone. The plan's
 * fingerprint covers everything it read, so a review can tell when anything changed.
 */
final class ConfigurationPlanner
{
    /**
     * Create a new ConfigurationPlanner instance.
     *
     * Plans configuration documents.
     *
     * @param  ConfigurationDocument  $documents  Parses and validates the document.
     * @param  ConfigurationBindings  $bindings  Resolves the document's names to records.
     * @param  Entitlements  $entitlements  Checks the plan includes workers and resources when the document asks for them.
     */
    public function __construct(private readonly ConfigurationDocument $documents, private readonly ConfigurationBindings $bindings, private readonly Entitlements $entitlements) {}

    /**
     * Work out the changes applying the document would make, with a keyed fingerprint of everything it read. Documents
     * that would break an invariant (a second or missing production environment, a type change of a resource, removing
     * something configuration doesn't own, planning while a deploy runs) are refused.
     *
     * @param  Project  $project
     * @param  User  $user
     * @param  string  $yaml
     * @param  array<string, mixed>  $bindings
     * @return array{version: int, project_id: string, changes: list<array<string, mixed>>, fingerprint: string, omitted_objects: string, apply_available: bool}
     */
    public function plan(Project $project, User $user, string $yaml, array $bindings): array
    {
        $document = $this->documents->parse($yaml);
        $resolved = $this->bindings->resolve($project, $user, $document, $bindings);
        /** @var Collection<string, Environment> $environments */
        $environments = $project->environments()->with(['processes', 'resources', 'variables'])->get()->keyBy('slug');
        $kinds = $environments->map(fn (Environment $environment): string => $environment->kind->value)->all();
        foreach ($document['environments'] as $slug => $desired) {
            $kinds[$slug] = $desired['type'];
        }
        foreach ($document['remove']['environments'] ?? [] as $slug) {
            unset($kinds[$slug]);
        }
        if (count(array_keys($kinds, EnvironmentKind::Production->value, true)) !== 1) {
            $this->invalid('A project has exactly one production environment. It can’t be added, removed or changed by configuration.');
        }
        $ownerships = ConfigurationOwnership::query()->where('project_id', $project->id)->orderBy('id')->get();
        $changes = [];
        foreach ($document['remove']['environments'] ?? [] as $slug) {
            array_push($changes, ...$this->removal($project, $slug, $environments->get($slug), $ownerships));
        }
        foreach ($document['environments'] as $slug => $desired) {
            foreach (['processes' => 'deploy.workers', 'resources' => 'deploy.resources'] as $section => $flag) {
                if (! empty($desired[$section]) && ! $this->entitlements->for($project->account)->has($flag)) {
                    $this->invalid('The account’s Deploy plan doesn’t include a requested capability.');
                }
            }
            $existing = $environments->get($slug);
            if ($existing?->kind === EnvironmentKind::Production && $desired['type'] !== EnvironmentKind::Production->value) {
                $this->invalid('A project has exactly one production environment. It can’t be added, removed or changed by configuration.');
            }
            $managedValkey = array_diff($existing?->resources->where('type', 'valkey')->where('is_managed', true)->pluck('name')->all() ?? [], $desired['remove']['resources'] ?? []);
            foreach ($desired['resources'] ?? [] as $name => $resource) {
                if ($resource['managed'] && $resource['type'] === 'valkey') {
                    $managedValkey[] = $name;
                }
            }
            if (count(array_unique($managedValkey)) > 1) {
                $this->invalid('An environment supports one managed Valkey resource. Detach the existing one before adding a replacement.');
            }
            $websiteId = $resolved['placements'][$desired['placement']]['website_id'];
            if (Build::query()->where('website_id', $websiteId)->whereIn('status', Build::ACTIVE)->exists()
                || ($existing !== null && Build::query()->where('environment_id', $existing->id)->whereIn('status', Build::ACTIVE)->exists())) {
                $this->invalid('Wait for the running deploy to finish before reviewing configuration.');
            }
            $attributes = [
                'kind' => $desired['type'], 'runtime_type' => $desired['runtime']['type'], 'build_command' => $desired['runtime']['build_command'] ?? null,
                'start_command' => $desired['runtime']['start_command'] ?? null, 'container_port' => $desired['runtime']['port'] ?? null,
                'dockerfile_path' => $desired['runtime']['dockerfile_path'] ?? null,
            ];
            $fields = array_keys(array_filter($attributes, fn (mixed $value, string $key): bool => $existing === null
                || ($key === 'kind' ? $existing->kind->value : $existing->getAttribute($key)) !== $value, ARRAY_FILTER_USE_BOTH));
            if (isset($desired['deploy'])) {
                $repository = Repository::query()->findOrFail($resolved['repositories'][$desired['deploy']['repository']]['repository_id']);
                if ($existing === null || $repository->environment_id !== $existing->id) {
                    $fields[] = 'repository';
                }
                $changes[] = ['environment' => $slug, 'kind' => 'deployment', 'name' => $desired['deploy']['repository'], 'action' => 'deploy', 'fields' => [], 'requires_approval' => (bool) $existing?->requires_deployment_approval];
            }
            $changes[] = ['environment' => $slug, 'kind' => 'environment', 'name' => $slug, 'action' => $this->action($ownerships, $slug, 'environment', $slug, $existing, (bool) ($desired['adopt'] ?? false)), 'fields' => $fields];
            foreach (['processes', 'resources', 'variables'] as $kind) {
                foreach ($desired[$kind] ?? [] as $name => $settings) {
                    $current = $existing?->{$kind}->firstWhere($kind === 'variables' ? 'key' : 'name', $name);
                    if ($current instanceof EnvironmentResource && ($current->type !== $settings['type'] || $current->is_managed !== $settings['managed'])) {
                        $this->invalid('Changing a resource’s type or management needs an explicit detachment review first. Remote data isn’t migrated automatically.');
                    }
                    $changes[] = ['environment' => $slug, 'kind' => $kind, 'name' => $name, 'action' => $this->action($ownerships, $slug, $kind, (string) $name, $current, (bool) ($settings['adopt'] ?? false)), 'fields' => array_values(array_diff(array_keys($settings), ['adopt']))];
                }
            }
            foreach ($desired['remove'] ?? [] as $kind => $names) {
                foreach ($names as $name) {
                    $current = $existing?->{$kind}->firstWhere($kind === 'variables' ? 'key' : 'name', $name);
                    if ($current !== null && $this->action($ownerships, $slug, $kind, $name, $current, false) !== 'update') {
                        $this->invalid('Only objects configuration owns can be removed. Adopt the object in a separate review first.');
                    }
                    $changes[] = ['environment' => $slug, 'kind' => $kind, 'name' => $name, 'action' => $current === null ? 'absent' : ($kind === 'resources' ? 'detach' : 'remove'), 'fields' => [], 'remote_data_deleted' => false];
                }
            }
        }
        // Key the digest so low-entropy values can't be guessed from it. Secret sources are included as stored (encrypted), never decrypted.
        $state = $environments->sortKeys()->map(function (Environment $environment): array {
            $record = $environment->getRawOriginal();
            foreach (['processes', 'resources', 'variables'] as $relation) {
                $record[$relation] = $environment->{$relation}->sortBy('id')->map(fn (Model $model): array => $model->getRawOriginal())->values()->all();
            }

            return $record;
        })->all();
        $fingerprint = hash_hmac('sha256', json_encode([
            $document, $resolved, $state, $ownerships->map(fn (ConfigurationOwnership $ownership): array => $ownership->getRawOriginal())->all(),
            EnvironmentVariable::query()->whereIn('id', array_column($resolved['secrets'], 'variable_id'))->orderBy('id')->get()->map(fn (EnvironmentVariable $variable): array => $variable->getRawOriginal())->all(),
            Repository::query()->whereIn('id', array_column($resolved['repositories'], 'repository_id'))->orderBy('id')->get(['id', 'environment_id', 'updated_at'])->toArray(),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return ['version' => 2, 'project_id' => $project->id, 'changes' => $changes, 'fingerprint' => $fingerprint, 'omitted_objects' => 'preserved', 'apply_available' => ! collect($changes)->contains('action', 'adoption_required')];
    }

    /**
     * Decide what applying would do to one object: update what configuration owns, create what's missing, adopt what
     * exists when the document says so, or require adoption. Ownership that no longer matches its target, or a target
     * owned under another name, is refused.
     *
     * @param  Collection<int, ConfigurationOwnership>  $ownerships
     * @param  string  $slug
     * @param  string  $kind
     * @param  string  $name
     * @param  Model|null  $current
     * @param  bool  $adopt
     * @return string
     */
    private function action(Collection $ownerships, string $slug, string $kind, string $name, ?Model $current, bool $adopt): string
    {
        $ownership = $ownerships->first(fn (ConfigurationOwnership $record): bool => $record->environment_slug === $slug && $record->kind === $kind && $record->logical_name === $name);
        $key = $current === null ? null : (string) $current->getKey();
        if ($ownership !== null && $ownership->resource_key !== $key) {
            $this->invalid('Configuration ownership no longer matches the target. Resolve ownership before reviewing changes.');
        }
        if ($key !== null && ConfigurationOwnership::query()->where('kind', $kind)->where('resource_key', $key)->when($ownership !== null, fn ($query) => $query->whereKeyNot($ownership?->id))->exists()) {
            $this->invalid('The target is already managed under another configuration name.');
        }

        return $ownership !== null ? 'update' : ($current !== null ? ($adopt ? 'adopt' : 'adoption_required') : 'create');
    }

    /**
     * Plan removing a whole environment: only one configuration owns (with everything in it), not production, and
     * nothing running.
     *
     * @param  Project  $project
     * @param  string  $slug
     * @param  Environment|null  $environment
     * @param  Collection<int, ConfigurationOwnership>  $ownerships
     * @return list<array<string, mixed>>
     */
    private function removal(Project $project, string $slug, ?Environment $environment, Collection $ownerships): array
    {
        $change = fn (string $kind, string $name, string $action): array => ['environment' => $slug, 'kind' => $kind, 'name' => $name, 'action' => $action, 'fields' => [], 'remote_data_deleted' => false, 'remote_services_changed' => false];
        if ($environment === null) {
            if ($ownerships->contains('environment_slug', $slug)) {
                $this->invalid('Resolve stale configuration ownership before removing an environment.');
            }

            return [$change('environment', $slug, 'absent')];
        }
        if ($this->action($ownerships, $slug, 'environment', $slug, $environment, false) !== 'update') {
            $this->invalid('Only environments configuration owns can be removed. Adopt the environment in a separate review first.');
        }
        if (Build::query()->where('environment_id', $environment->id)->whereIn('status', Build::ACTIVE)->exists()
            || ConfigurationOperation::query()->where('environment_id', $environment->id)->whereNotIn('status', ConfigurationOperation::FINISHED)->exists()) {
            $this->invalid('Finish or cancel running deploys and configuration operations before removing an environment.');
        }
        $changes = [];
        foreach (['processes', 'resources', 'variables'] as $kind) {
            foreach ($environment->{$kind}->sortBy('id') as $child) {
                $name = $child instanceof EnvironmentVariable ? $child->key : $child->name;
                if ($this->action($ownerships, $slug, $kind, $name, $child, false) !== 'update') {
                    $this->invalid('Removing the environment would delete objects configuration doesn’t own. Adopt each one in a separate review first.');
                }
                $changes[] = $change($kind, $name, $kind === 'resources' ? 'detach' : 'remove');
            }
        }
        $changes[] = $change('environment', $slug, 'remove');

        return $changes;
    }

    /**
     * Refuse the plan with a message on `plan`.
     *
     * @param  string  $message
     * @return never
     */
    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['plan' => $message]);
    }
}
