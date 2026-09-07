<?php

namespace App\Services;

use App\Models\Build;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\Environment;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationConfigurationRetries
{
    /**
     * Bind explicit retry preparation, deployment snapshots and receipt synchronization.
     *
     * @param  ApplicationConfigurationBuilds  $builds  Revalidates and prepares the failed operation's replacement build.
     * @param  DeploymentRequest  $deployments  Captures current environment attributes for the retry; delivery dispatches its build separately.
     * @param  ApplicationConfigurationResults  $results  Refreshes aggregate receipt outcomes after retry reservation.
     */
    public function __construct(
        private readonly ApplicationConfigurationBuilds $builds,
        private readonly DeploymentRequest $deployments,
        private readonly ApplicationConfigurationResults $results,
    ) {}

    /** Retry an exact failed operation once. Retrying its replacement needs that new identity. */
    public function retry(ConfigurationOperation $original, User $user): ConfigurationOperation
    {
        $projectId = $original->application->review->project_id;

        $retry = DB::transaction(function () use ($original, $user, $projectId) {
            $project = ApplicationConfigurationLocks::project($projectId);
            $original = ConfigurationOperation::query()->findOrFail($original->id);
            $review = $original->application->review;
            $user = User::query()->findOrFail($user->id);
            if ((int) $review->project_id !== (int) $project->id
                || (int) $review->requested_by !== (int) $user->id
                || (int) $project->organization_id !== (int) $user->current_organization_id
                || ! $project->organization->permits($user, 'manage')) {
                throw new AuthorizationException;
            }
            $environment = Environment::query()->lockForUpdate()->find($original->environment_id);
            if ($environment) {
                foreach (['processes', 'resources', 'variables'] as $relation) {
                    $environment->setRelation($relation, $environment->{$relation}()->orderBy('id')->lockForUpdate()->get());
                }
            }
            $website = $environment ? Website::query()->lockForUpdate()->find($environment->website_id) : null;
            $repository = Repository::query()->lockForUpdate()->find($original->payload['repository_id'] ?? 0);
            $original = ConfigurationOperation::query()->lockForUpdate()->findOrFail($original->id);
            if ($existing = $original->retry()->first()) {
                // Replays only return the durable receipt, including after completion.
                return $existing;
            }
            $build = $original->build;
            $canceledBeforeBuild = ! $build && $original->status === 'canceled' && ! $original->started_at;
            if (! $canceledBeforeBuild && (! $build || ! in_array($build->status, [Build::STATUS_FAILED, Build::STATUS_CANCELED, Build::STATUS_REJECTED], true))) {
                $this->invalid('Only an operation with a recorded failed or canceled deployment can be retried.');
            }
            if (! $environment || (int) $environment->project_id !== (int) $project->id
                || ! $website || ! $repository || (int) $repository->website_id !== (int) $website->id) {
                $this->invalid('The deployment target changed. Create a new configuration review.');
            }
            $latest = ConfigurationOperation::query()->where('environment_id', $environment->id)->where('kind', 'deploy')->latest('id')->first();
            if ($latest?->id !== $original->id) {
                $this->invalid('A newer deployment intent exists. Use its receipt or create a new configuration review.');
            }
            $current = $this->deployments->attributesForEnvironment($repository, $environment, $user);
            $snapshot = $original->payload['attributes']['environment_payload'] ?? null;
            if ($snapshot !== $current['environment_payload']) {
                $this->invalid('The environment changed after this deployment. Create a new configuration review.');
            }
            $payload = $original->payload;
            // Keep the exact failed runtime/secret snapshot, but never inherit approval.
            $payload['attributes']['status'] = $current['status'];
            $retry = $original->application->operations()->create([
                'environment_slug' => $original->environment_slug, 'environment_id' => $original->environment_id,
                'kind' => $original->kind, 'payload' => $payload,
                'intent_digest' => ApplicationConfigurationRepositoryIdentity::intentDigest($payload['repository_fingerprint'], $payload['attributes']),
                'retry_of_operation_id' => $original->id, 'retry_sequence' => $original->retry_sequence + 1,
                'available_at' => now(),
            ]);
            // Reserve under the same transaction. Rechecks requester access, target
            // readiness, repository fingerprint, deployment windows/locks and active builds.
            if (! $this->builds->prepare($retry)) {
                $this->invalid('Retry is blocked by current access, repository, target or deployment gates. Create a new review if configuration changed.');
            }
            ConfigurationApplication::query()->whereHas('referencedOperations', fn ($query) => $query->where('configuration_operations.id', $original->id))
                ->each(fn ($application) => $application->referencedOperations()->syncWithoutDetaching([$retry->id]));

            return $retry->fresh();
        }, 5);

        $this->results->refresh($retry->application);
        ConfigurationApplication::query()->whereHas('referencedOperations', fn ($query) => $query->where('configuration_operations.id', $retry->id))
            ->each(fn ($application) => $this->results->refresh($application));

        return $retry;
    }

    /**
     * Reject a retry that cannot safely reuse the saved configuration intent.
     *
     * @param  string  $message  A sanitized operator-facing explanation of the rejected retry.
     * @return never This method always throws.
     *
     * @throws ValidationException With an operation error.
     */
    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
