<?php

namespace App\Observers;

use App\Actions\Repository\QueuePendingWebhookDeploymentAction;
use App\Models\Build;
use App\Services\ActivityRecorder;
use App\Services\IncidentNotifier;

class BuildActivityObserver
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly QueuePendingWebhookDeploymentAction $queuePendingDeployment,
        private readonly IncidentNotifier $incidents,
    ) {}

    public function created(Build $build): void
    {
        $this->record($build, $build->trigger_source === Build::TRIGGER_PROMOTION
            ? "Release promotion was requested from build #{$build->promoted_from_build_id}."
            : 'Deployment was queued.');
    }

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

    private function record(Build $build, string $message): void
    {
        $build->loadMissing('repository');

        if ($build->repository?->user_id) {
            $this->activity->record($build, $build->requested_by ?: $build->repository->user_id, 'deployment', $message);
        }
    }
}
