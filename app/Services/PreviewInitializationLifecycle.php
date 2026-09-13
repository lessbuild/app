<?php

namespace App\Services;

use App\Data\PreviewInitialization;
use App\Models\Build;
use App\Models\PreviewDeployment;
use App\Models\Project;

class PreviewInitializationLifecycle
{
    /**
     * Resolve curated commands and match their durable state to the deployment callback stages.
     *
     * @param  PreviewStackCatalog  $catalog  Resolves the explicit initialization declaration for a project preset.
     * @param  RepositoryDeploymentPlan  $plan  Supplies the existing post-deployment callback stage without changing stage numbering.
     */
    public function __construct(
        private readonly PreviewStackCatalog $catalog,
        private readonly RepositoryDeploymentPlan $plan,
    ) {}

    /**
     * Determine the initial state for a project preview.
     *
     * @param  Project  $project  Project whose curated preset selects the initialization command.
     * @return string A persisted initialization state.
     */
    public function statusFor(Project $project): string
    {
        return $this->definitionFor($project)
            ? PreviewDeployment::INITIALIZATION_PENDING
            : PreviewDeployment::INITIALIZATION_NOT_CONFIGURED;
    }

    /**
     * Return the encrypted-build payload for the next safe initialization attempt.
     *
     * The payload contains only curated command text and a revision/attempt identity. It
     * is stored through Build's encrypted environment cast and is omitted after success.
     *
     * @param  PreviewDeployment  $preview  Preview whose current revision is being deployed.
     * @return array{command: string, attempt: int, revision: string}|null The attempt payload, or null when no run is needed.
     */
    public function payload(PreviewDeployment $preview): ?array
    {
        if (! in_array($preview->initialization_status, [
            PreviewDeployment::INITIALIZATION_PENDING,
            PreviewDeployment::INITIALIZATION_FAILED,
        ], true)) {
            return null;
        }

        $definition = $this->definitionFor($preview->project);
        if (! $definition) {
            return null;
        }

        return [
            'command' => $definition->command,
            'attempt' => (int) $preview->initialization_attempts + 1,
            'revision' => (string) $preview->revision,
        ];
    }

    /**
     * Reset an incomplete initialization for a newly requested revision.
     *
     * @param  PreviewDeployment  $preview  Preview receiving the new revision.
     * @param  Project  $project  Project whose current preset determines support.
     * @return void No value; completed first-release initialization remains complete across revisions.
     */
    public function resetForRevision(PreviewDeployment $preview, Project $project): void
    {
        if ($preview->initialization_status === PreviewDeployment::INITIALIZATION_SUCCEEDED) {
            return;
        }

        $preview->update([
            'initialization_status' => $this->statusFor($project),
            'initialization_attempts' => 0,
            'initialization_build_id' => null,
            'initialization_error' => null,
            'initialization_completed_at' => null,
        ]);
    }

    /**
     * Claim a persisted build as the current initialization attempt.
     *
     * @param  PreviewDeployment  $preview  Preview whose build is being dispatched.
     * @param  Build  $build  Newly persisted build carrying the encrypted attempt payload.
     * @param  array{command: string, attempt: int, revision: string}|null  $payload  Payload copied into the build snapshot.
     * @return void No value; an absent payload leaves unsupported or completed previews unchanged.
     */
    public function begin(PreviewDeployment $preview, Build $build, ?array $payload): void
    {
        if (! $payload) {
            return;
        }

        $preview->update([
            'initialization_status' => PreviewDeployment::INITIALIZATION_RUNNING,
            'initialization_attempts' => $payload['attempt'],
            'initialization_build_id' => $build->id,
            'initialization_error' => null,
            'initialization_completed_at' => null,
        ]);
    }

    /**
     * Mark the current initialization attempt complete at the existing post-deployment stage.
     *
     * @param  Build  $build  Build receiving a monotonic signed status callback.
     * @param  int  $status  Validated deployment stage.
     * @return void No value; stale, unrelated and early callbacks are ignored.
     */
    public function recordProgress(Build $build, int $status): void
    {
        $preview = $this->forCurrentBuild($build);
        if (! $preview) {
            return;
        }
        if ($status < $this->plan->postDeploymentStage()) {
            return;
        }

        $preview->update([
            'initialization_status' => PreviewDeployment::INITIALIZATION_SUCCEEDED,
            'initialization_error' => null,
            'initialization_completed_at' => now(),
        ]);
    }

    /**
     * Mark an attempt complete when the persisted build itself is already terminal-successful.
     *
     * @param  Build  $build  Successful build whose callback may have been delivered out of order.
     * @return void No value; stale callbacks cannot complete a newer attempt.
     */
    public function recordSuccess(Build $build): void
    {
        $this->forCurrentBuild($build)
            ?->update([
                'initialization_status' => PreviewDeployment::INITIALIZATION_SUCCEEDED,
                'initialization_error' => null,
                'initialization_completed_at' => now(),
            ]);
    }

    /**
     * Mark an attempt failed without copying remote output into durable preview metadata.
     *
     * @param  Build  $build  Failed build whose current attempt may be retried.
     * @return void No value; stale callbacks cannot overwrite a newer revision or attempt.
     */
    public function recordFailure(Build $build): void
    {
        $this->forCurrentBuild($build)
            ?->update([
                'initialization_status' => PreviewDeployment::INITIALIZATION_FAILED,
                'initialization_error' => 'Preview initialization did not complete.',
                'initialization_completed_at' => null,
            ]);
    }

    /**
     * Record cancellation as a retryable initialization failure.
     *
     * @param  Build  $build  Canceled build whose initialization claim must be released.
     * @return void No value; completed attempts remain completed.
     */
    public function recordStopped(Build $build): void
    {
        $this->forCurrentBuild($build)
            ?->update([
                'initialization_status' => PreviewDeployment::INITIALIZATION_FAILED,
                'initialization_error' => 'Preview initialization was stopped before completion.',
                'initialization_completed_at' => null,
            ]);
    }

    /**
     * @param  Project  $project  Project whose preset supplies the command.
     * @return PreviewInitialization|null Curated initialization definition, if supported.
     */
    private function definitionFor(Project $project): ?PreviewInitialization
    {
        return $this->catalog->for($project)->initialization;
    }

    /**
     * Find the preview that still owns this exact build attempt.
     *
     * @param  Build  $build  Build being reconciled.
     * @return PreviewDeployment|null Matching current preview, or null for stale callbacks.
     */
    private function forCurrentBuild(Build $build): ?PreviewDeployment
    {
        if (! $build->repository_id || ! $build->environment_id || ! $build->id) {
            return null;
        }

        return PreviewDeployment::query()
            ->where('repository_id', $build->repository_id)
            ->where('environment_id', $build->environment_id)
            ->where('initialization_build_id', $build->id)
            ->where('revision', (string) $build->revision)
            ->where('initialization_status', PreviewDeployment::INITIALIZATION_RUNNING)
            ->first();
    }
}
