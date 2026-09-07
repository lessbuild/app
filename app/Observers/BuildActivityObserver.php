<?php

namespace App\Observers;

use App\Actions\Repository\QueuePendingWebhookDeploymentAction;
use App\Models\Build;
use App\Services\ActivityRecorder;
use App\Services\IncidentNotifier;

class BuildActivityObserver
{
    /**
     * Coordinate deployment audit events, incident state, and pending webhook dispatch.
     *
     * @param  ActivityRecorder  $activity  Recorder for attributed lifecycle events in the application activity stream.
     * @param  QueuePendingWebhookDeploymentAction  $queuePendingDeployment  Action that drains an eligible retained webhook revision after website capacity becomes available.
     * @param  IncidentNotifier  $incidents  Incident service used for lifecycle failure and recovery notifications.
     */
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly QueuePendingWebhookDeploymentAction $queuePendingDeployment,
        private readonly IncidentNotifier $incidents,
    ) {}

    /**
     * Record either a promotion request or a queued deployment when a build is created.
     *
     * @param  Build  $build  Build record whose persisted deployment state and relationships are used by this operation.
     */
    public function created(Build $build): void
    {
        $this->record($build, $build->trigger_source === Build::TRIGGER_PROMOTION
            ? "Release promotion was requested from build #{$build->promoted_from_build_id}."
            : 'Deployment was queued.');
    }

    /**
     * Record newly terminal deployment states, notify the owner on failure, and drain pending webhook work after terminal transitions.
     *
     * @param  Build  $build  Build record whose persisted deployment state and relationships are used by this operation.
     */
    public function updated(Build $build): void
    {
        if ($build->wasChanged('status') && in_array($build->status, [
            Build::STATUS_SUCCEEDED,
            Build::STATUS_FAILED,
            Build::STATUS_CANCELED,
        ], true)) {
            $this->record($build, "Deployment {$build->status}.");

            $build->loadMissing('repository');
            if ($build->repository) {
                if ($build->status === Build::STATUS_FAILED && $build->repository->user) {
                    $this->incidents->fail(
                        $build->repository->user,
                        'deployment',
                        $build->id,
                        "Deployment #{$build->id} failed",
                        $build->failure_message ?: 'The deployment failed before it completed.',
                    );
                }

                $this->queuePendingDeployment->handle($build->repository);
            }
        }
    }

    /**
     * Attribute a deployment activity event to its requester, falling back to the repository owner when available.
     *
     * @param  Build  $build  Build record whose persisted deployment state and relationships are used by this operation.
     * @param  string  $message  Human-readable lifecycle event to retain in the activity stream.
     */
    private function record(Build $build, string $message): void
    {
        $build->loadMissing('repository');

        if ($build->repository?->user_id) {
            $this->activity->record($build, $build->requested_by ?: $build->repository->user_id, 'deployment', $message);
        }
    }
}
