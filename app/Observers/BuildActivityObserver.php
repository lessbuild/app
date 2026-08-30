<?php

namespace App\Observers;

use App\Models\Build;
use App\Services\ActivityRecorder;

class BuildActivityObserver
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    public function created(Build $build): void
    {
        $this->record($build, 'Deployment was queued.');
    }

    public function updated(Build $build): void
    {
        if ($build->wasChanged('status') && in_array($build->status, [
            Build::STATUS_SUCCEEDED,
            Build::STATUS_FAILED,
            Build::STATUS_CANCELED,
        ], true)) {
            $this->record($build, "Deployment {$build->status}.");
        }
    }

    private function record(Build $build, string $message): void
    {
        $build->loadMissing('repository');

        if ($build->repository?->user_id) {
            $this->activity->record($build, $build->repository->user_id, 'deployment', $message);
        }
    }
}
