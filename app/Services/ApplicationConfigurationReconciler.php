<?php

namespace App\Services;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationOwnership;
use App\Models\ConfigurationReview;
use App\Models\EnvironmentVariable;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Str;

class ApplicationConfigurationReconciler
{
    public function __construct(
        private readonly ApplicationConfigurationTransaction $transactions,
        private readonly ApplicationConfigurationDocument $documents,
        private readonly ApplicationConfigurationBindings $bindings,
        private readonly ApplicationConfigurationVariables $variables,
        private readonly DeploymentRequest $deployments,
    ) {}

    /** Local reconciliation only. Does not claim that remote services have been deployed. */
    public function apply(ConfigurationReview $review, User $user): ConfigurationApplication
    {
        return $this->transactions->run($review, $user, function (ConfigurationReview $review, ConfigurationApplication $application) use ($user): void {
            $document = $this->documents->parse($review->document);
            $project = $review->project;
            $resolved = $this->bindings->resolve($project, $user, $document, $review->bindings);
            foreach ($document['environments'] as $slug => $desired) {
                $placement = $resolved['placements'][$desired['placement']];
                $environment = $project->environments()->firstOrNew(['slug' => $slug]);
                if (! $environment->exists) {
                    $environment->name = Str::headline($slug);
                }
                $environment->fill([
                    'type' => $desired['type'], 'website_id' => $placement['website_id'], 'server_id' => $placement['server_id'],
                    'runtime_type' => $desired['runtime']['type'],
                    'build_command' => $desired['runtime']['build_command'] ?? null,
                    'start_command' => $desired['runtime']['start_command'] ?? null,
                    'container_port' => $desired['runtime']['port'] ?? null,
                    'dockerfile_path' => $desired['runtime']['dockerfile_path'] ?? null,
                ]);
                if ($environment->isDirty()) {
                    $environment->save();
                }
                $this->claim($review, $slug, 'environment', $slug, $environment->id);
                foreach ($desired['processes'] ?? [] as $name => $settings) {
                    $process = $environment->processes()->firstOrNew(['name' => $name]);
                    foreach (['type', 'command', 'replicas'] as $field) {
                        if ($process->{$field} !== $settings[$field]) {
                            $process->{$field} = $settings[$field];
                        }
                    }
                    if ($process->isDirty()) {
                        $process->save();
                    }
                    $this->claim($review, $slug, 'processes', $name, $process->id);
                }
                foreach ($desired['resources'] ?? [] as $name => $settings) {
                    $resource = $environment->resources()->firstOrNew(['name' => $name]);
                    $configuration = $resource->configuration ?? ['variables' => [], 'container_name' => null];
                    if (! $settings['managed'] && array_key_exists('variable_refs', $settings)) {
                        $resourceVariables = [];
                        foreach ($settings['variable_refs'] as $key => $reference) {
                            $source = $resolved['secrets'][$reference];
                            $variable = EnvironmentVariable::query()->whereKey($source['variable_id'])->where('current_version', $source['version'])
                                ->where('is_secret', true)->whereIn('scope', ['runtime', 'all'])
                                ->whereHas('environment.project', fn ($query) => $query->where('organization_id', $project->organization_id))
                                ->lockForUpdate()->firstOrFail();
                            $resourceVariables[$key] = $variable->value;
                        }
                        $configuration = ['variables' => $resourceVariables, 'container_name' => null];
                    }
                    if ($settings['managed']) {
                        $type = $settings['type'];
                        $variables = [];
                        if (in_array($type, ['mysql', 'postgresql'], true)) {
                            $website = $environment->website;
                            $variables = ['DB_CONNECTION' => $type === 'postgresql' ? 'pgsql' : 'mysql',
                                'DB_HOST' => $type === 'postgresql' ? '127.0.0.1' : $website->server->public_ip,
                                'DB_PORT' => $type === 'postgresql' ? '5432' : '3306',
                                'DB_DATABASE' => $website->databaseIdentifier(), 'DB_USERNAME' => $website->databaseIdentifier(),
                                'DB_PASSWORD' => $website->database_password];
                        } elseif ($type === 'redis') {
                            $variables = ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379'];
                        } elseif ($type === 'valkey') {
                            $port = (string) (16379 + ($environment->id % 10000));
                            $variables = ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => $port, 'VALKEY_HOST' => '127.0.0.1', 'VALKEY_PORT' => $port];
                        }
                        $configuration = ['variables' => $variables, 'container_name' => $type === 'valkey' ? 'buildpusher-valkey-'.$environment->id.'-'.Str::slug($name) : null];
                    }
                    $resource->fill(['type' => $settings['type'], 'is_managed' => $settings['managed']]);
                    if ($resource->configuration !== $configuration) {
                        $resource->configuration = $configuration;
                    }
                    if ($resource->isDirty()) {
                        $resource->save();
                    }
                    $this->claim($review, $slug, 'resources', $name, $resource->id);
                }
                foreach ($desired['variables'] ?? [] as $name => $settings) {
                    $source = $resolved['secrets'][$settings['secret_ref']];
                    $variable = $this->variables->synchronize($environment, $name, $source['variable_id'], $source['version'], $settings['scope'], $user);
                    $this->claim($review, $slug, 'variables', $name, $variable->id);
                }
                foreach ($desired['remove'] ?? [] as $kind => $names) {
                    foreach ($names as $name) {
                        $record = $environment->{$kind}()->where($kind === 'variables' ? 'key' : 'name', $name)->first();
                        if ($record) {
                            $record->delete();
                            ConfigurationOwnership::query()->where('project_id', $project->id)
                                ->where('environment_slug', $slug)->where('kind', $kind)->where('logical_name', $name)->delete();
                        }
                    }
                }
                if (isset($desired['deploy'])) {
                    $repository = Repository::query()->findOrFail($resolved['repositories'][$desired['deploy']['repository']]['repository_id']);
                    $attributes = $this->deployments->attributesForEnvironment($repository, $environment->fresh(), $user);
                    $intentDigest = ApplicationConfigurationRepositoryIdentity::intentDigest(
                        $resolved['repositories'][$desired['deploy']['repository']]['fingerprint'], $attributes,
                    );
                    $previous = ConfigurationOperation::query()->where('environment_id', $environment->id)->where('kind', 'deploy')->latest('id')->first();
                    if ($previous && hash_equals((string) $previous->intent_digest, $intentDigest)) {
                        $application->referencedOperations()->syncWithoutDetaching([$previous->id]);

                        continue;
                    }
                    $application->operations()->create([
                        'environment_slug' => $slug, 'environment_id' => $environment->id, 'kind' => 'deploy',
                        'intent_digest' => $intentDigest,
                        'payload' => ['repository_id' => $repository->id,
                            'repository_fingerprint' => $resolved['repositories'][$desired['deploy']['repository']]['fingerprint'],
                            'attributes' => $attributes],
                        'available_at' => now(),
                    ]);
                }
            }
            foreach ($document['remove']['environments'] ?? [] as $slug) {
                // The transaction revalidates every owned child and dependency before
                // reaching this point. FK cascades remove only reviewed local records.
                $environment = $project->environments()->where('slug', $slug)->first();
                if ($environment) {
                    $environment->delete();
                    ConfigurationOwnership::query()->where('project_id', $project->id)
                        ->where('environment_slug', $slug)->delete();
                }
            }
        });
    }

    private function claim(ConfigurationReview $review, string $slug, string $kind, string $name, int $id): void
    {
        ConfigurationOwnership::query()->updateOrCreate([
            'project_id' => $review->project_id, 'environment_slug' => $slug, 'kind' => $kind, 'logical_name' => $name,
        ], ['resource_id' => $id, 'configuration_review_id' => $review->id]);
    }
}
