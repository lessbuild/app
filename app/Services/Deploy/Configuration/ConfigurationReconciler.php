<?php

declare(strict_types=1);

namespace App\Services\Deploy\Configuration;

use App\Enums\EnvironmentKind;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationOwnership;
use App\Models\ConfigurationReview;
use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Services\Deploy\BuildPayload;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Makes the project match a reviewed document, inside the applying transaction (ported from Deployer): environments,
 * processes, resources and variables it names are created or updated and marked as owned; removals delete owned
 * objects; each deploy becomes an operation, unless the last deploy of that environment had exactly the same intent.
 */
final class ConfigurationReconciler
{
    public function __construct(private readonly ConfigurationDocument $documents, private readonly ConfigurationBindings $bindings, private readonly BuildPayload $payload) {}

    public function apply(ConfigurationReview $review, ConfigurationApplication $application, User $user): void
    {
        $document = $this->documents->parse($review->document);
        $project = $review->project;
        $resolved = $this->bindings->resolve($project, $user, $document, $review->bindings);
        foreach ($document['environments'] as $slug => $desired) {
            $this->environment($review, $application, $project, $user, $slug, $desired, $resolved);
        }
        foreach ($document['remove']['environments'] ?? [] as $slug) {
            $environment = $project->environments()->where('slug', $slug)->first();
            if ($environment !== null) {
                $environment->delete();
                ConfigurationOwnership::query()->where('project_id', $project->id)->where('environment_slug', $slug)->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $desired
     * @param  array{placements: array<string, array{website_id: int}>, secrets: array<string, array{variable_id: int, version: int}>, repositories: array<string, array{repository_id: int, fingerprint: string}>}  $resolved
     */
    private function environment(ConfigurationReview $review, ConfigurationApplication $application, Project $project, User $user, string $slug, array $desired, array $resolved): void
    {
        $website = Website::query()->with('server')->findOrFail($resolved['placements'][$desired['placement']]['website_id']);
        $environment = $project->environments()->where('slug', $slug)->first() ?? (new Environment)->forceFill(['project_id' => $project->id, 'slug' => $slug, 'name' => Str::headline($slug)]);
        $environment->forceFill([
            'kind' => EnvironmentKind::from($desired['type']), 'runtime_type' => $desired['runtime']['type'], 'build_command' => $desired['runtime']['build_command'] ?? null,
            'start_command' => $desired['runtime']['start_command'] ?? null, 'container_port' => $desired['runtime']['port'] ?? null, 'dockerfile_path' => $desired['runtime']['dockerfile_path'] ?? null,
        ]);
        if ($environment->isDirty()) {
            $environment->save();
        }
        $this->claim($review, $slug, 'environment', $slug, $environment->id);
        foreach ($desired['processes'] ?? [] as $name => $settings) {
            $process = $environment->processes()->where('name', $name)->first() ?? (new EnvironmentProcess)->forceFill(['environment_id' => $environment->id, 'name' => $name]);
            $process->forceFill(['type' => $settings['type'], 'command' => $settings['command'], 'replicas' => $settings['replicas']]);
            if ($process->isDirty()) {
                $process->save();
            }
            $this->claim($review, $slug, 'processes', $name, (string) $process->id);
        }
        foreach ($desired['resources'] ?? [] as $name => $settings) {
            $resource = $environment->resources()->where('name', $name)->first() ?? (new EnvironmentResource)->forceFill(['environment_id' => $environment->id, 'name' => $name, 'status' => 'ready']);
            $resource->forceFill(['type' => $settings['type'], 'is_managed' => $settings['managed'], 'configuration' => $this->resource($environment, $website, $name, $settings, $resolved['secrets'], $project)]);
            if ($resource->isDirty()) {
                $resource->save();
            }
            $this->claim($review, $slug, 'resources', $name, (string) $resource->id);
        }
        foreach ($desired['variables'] ?? [] as $name => $settings) {
            $variable = $this->variable($environment, $name, $resolved['secrets'][$settings['secret_ref']], $settings['scope'], $user, $project);
            $this->claim($review, $slug, 'variables', $name, (string) $variable->id);
        }
        foreach ($desired['remove'] ?? [] as $kind => $names) {
            foreach ($names as $name) {
                $environment->{$kind}()->where($kind === 'variables' ? 'key' : 'name', $name)->first()?->delete();
                ConfigurationOwnership::query()->where('project_id', $project->id)->where('environment_slug', $slug)->where('kind', $kind)->where('logical_name', $name)->delete();
            }
        }
        if (! isset($desired['deploy'])) {
            return;
        }
        $binding = $resolved['repositories'][$desired['deploy']['repository']];
        $repository = Repository::query()->with('website')->findOrFail($binding['repository_id']);
        if ($repository->environment_id !== $environment->id) {
            $repository->forceFill(['environment_id' => $environment->id])->save();
        }
        $repository->setRelation('environment', $environment->fresh(['variables', 'processes', 'resources']));
        $intent = ConfigurationIdentity::intent($binding['fingerprint'], $this->payload->for($repository));
        $previous = ConfigurationOperation::query()->where('environment_id', $environment->id)->where('kind', 'deploy')->latest('id')->first();
        if ($previous !== null && hash_equals($previous->intent_digest, $intent) && $previous->status !== 'canceled') {
            $application->referencedOperations()->syncWithoutDetaching([$previous->id]);

            return;
        }
        $operation = new ConfigurationOperation;
        $operation->forceFill([
            'configuration_application_id' => $application->id, 'environment_slug' => $slug, 'environment_id' => $environment->id, 'kind' => 'deploy',
            'status' => 'pending', 'intent_digest' => $intent, 'payload' => ['repository_id' => $repository->id, 'repository_fingerprint' => $binding['fingerprint']],
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, array{variable_id: int, version: int}>  $secrets
     * @return array{variables: array<string, string>, container_name: string|null}
     */
    private function resource(Environment $environment, Website $website, string $name, array $settings, array $secrets, Project $project): array
    {
        if ($settings['managed']) {
            $port = (string) (16379 + crc32($environment->id) % 10000);

            return ['variables' => match ($settings['type']) {
                'mysql', 'postgresql' => ['DB_CONNECTION' => $settings['type'] === 'postgresql' ? 'pgsql' : 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $settings['type'] === 'postgresql' ? '5432' : '3306',
                    'DB_DATABASE' => $website->databaseIdentifier(), 'DB_USERNAME' => $website->databaseIdentifier(), 'DB_PASSWORD' => (string) $website->database_password],
                'redis' => ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379'],
                default => ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => $port, 'VALKEY_HOST' => '127.0.0.1', 'VALKEY_PORT' => $port],
            }, 'container_name' => $settings['type'] === 'valkey' ? 'buildpusher-valkey-'.strtolower($environment->id).'-'.Str::slug($name) : null];
        }
        $variables = [];
        foreach ($settings['variable_refs'] ?? [] as $key => $reference) {
            $variables[$key] = $this->source($secrets[$reference], ['runtime', 'all'], $project)->value;
        }

        return ['variables' => $variables, 'container_name' => null];
    }

    /** @param array{variable_id: int, version: int} $binding */
    private function variable(Environment $environment, string $key, array $binding, string $scope, User $user, Project $project): EnvironmentVariable
    {
        $source = $this->source($binding, $scope === 'all' ? ['all'] : [$scope, 'all'], $project);
        $target = $environment->variables()->where('key', $key)->lockForUpdate()->first();
        if ($target !== null && $target->value === $source->value && $target->is_secret && $target->scope === $scope) {
            return $target;
        }
        $version = ($target->current_version ?? 0) + 1;
        $target ??= (new EnvironmentVariable)->forceFill(['environment_id' => $environment->id, 'key' => $key]);
        $target->forceFill(['value' => $source->value, 'is_secret' => true, 'scope' => $scope, 'current_version' => $version, 'updated_by' => $user->id, 'rotated_at' => $version > 1 ? now() : null])->save();
        $target->versions()->forceCreate(['version' => $version, 'value' => $source->value, 'created_by' => $user->id]);

        return $target;
    }

    /**
     * @param  array{variable_id: int, version: int}  $binding
     * @param  list<string>  $scopes
     */
    private function source(array $binding, array $scopes, Project $project): EnvironmentVariable
    {
        $source = EnvironmentVariable::query()->whereKey($binding['variable_id'])->where('current_version', $binding['version'])->where('is_secret', true)->whereIn('scope', $scopes)
            ->whereHas('environment.project', fn ($query) => $query->where('account_id', $project->account_id))->lockForUpdate()->first();

        return $source ?? throw ValidationException::withMessages(['bindings' => 'The reviewed secret binding is no longer available.']);
    }

    private function claim(ConfigurationReview $review, string $slug, string $kind, string $name, string $key): void
    {
        $ownership = ConfigurationOwnership::query()->where('project_id', $review->project_id)->where('environment_slug', $slug)->where('kind', $kind)->where('logical_name', $name)->first() ?? new ConfigurationOwnership;
        $ownership->forceFill(['project_id' => $review->project_id, 'environment_slug' => $slug, 'kind' => $kind, 'logical_name' => $name, 'resource_key' => $key, 'configuration_review_id' => $review->id])->save();
    }
}
