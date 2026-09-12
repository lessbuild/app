<?php

namespace App\Services;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationOwnership;
use App\Models\ConfigurationReview;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Str;

class ApplicationConfigurationEnvironmentReconciler
{
    /**
     * Bind the collaborators needed to converge one reviewed environment.
     *
     * @param  ApplicationConfigurationResourceConfiguration  $resourceConfiguration  Builds encrypted external or managed resource configuration.
     * @param  ApplicationConfigurationVariables  $variables  Applies encrypted variables and their version history.
     * @param  DeploymentRequest  $deployments  Builds deployment attributes for durable operation intents.
     */
    public function __construct(
        private readonly ApplicationConfigurationResourceConfiguration $resourceConfiguration,
        private readonly ApplicationConfigurationVariables $variables,
        private readonly DeploymentRequest $deployments,
    ) {}

    /**
     * Reconcile one declared environment inside the caller's existing transaction.
     *
     * @param  ConfigurationReview  $review  The immutable review whose identity owns reconciled children.
     * @param  ConfigurationApplication  $application  The durable receipt receiving operation references.
     * @param  Project  $project  The reviewed project and environment relationship owner.
     * @param  User  $user  The authorized reviewer attributed to variable changes and deployments.
     * @param  string  $slug  The logical environment slug.
     * @param  array<string, mixed>  $desired  The validated desired state for this environment.
     * @param  array<string, mixed>  $resolved  Authorized placement, secret and repository identities.
     * @return void All local writes remain in the transaction supplied by the application reconciler.
     */
    public function reconcile(
        ConfigurationReview $review,
        ConfigurationApplication $application,
        Project $project,
        User $user,
        string $slug,
        array $desired,
        array $resolved,
    ): void {
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
            $configuration = $this->resourceConfiguration->build($resource, $environment, $project, $name, $settings, $resolved['secrets']);
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

                return;
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

    /**
     * Record the reviewed ownership identity for a reconciled environment child.
     *
     * @param  ConfigurationReview  $review  The review whose project and identity own the claim.
     * @param  string  $slug  Logical environment slug inside the project.
     * @param  string  $kind  Owned resource category.
     * @param  string  $name  Logical name within that category and environment.
     * @param  int  $id  Database identifier of the reconciled resource.
     * @return void Create or update the local ownership row without changing remote resources.
     */
    private function claim(ConfigurationReview $review, string $slug, string $kind, string $name, int $id): void
    {
        ConfigurationOwnership::query()->updateOrCreate([
            'project_id' => $review->project_id, 'environment_slug' => $slug, 'kind' => $kind, 'logical_name' => $name,
        ], ['resource_id' => $id, 'configuration_review_id' => $review->id]);
    }
}
