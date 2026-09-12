<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\BuildApprovalNotifications;
use App\Services\DeploymentGate;
use Illuminate\Support\Facades\DB;

class ApproveBuildAction
{
    public function __construct(
        private readonly DeploymentGate $gate,
        private readonly ActivityRecorder $activity,
        private readonly BuildApprovalNotifications $notifications,
    ) {}

    /**
     * Atomically approve an eligible build that is still awaiting deployment review.
     *
     * @param  Build  $build  Build whose current approval state is being reviewed.
     * @param  User  $approver  Authorized user whose decision is recorded.
     * @param  string|null  $note  Validated optional approval note.
     * @return Build|null The queued build, or null when its state or deployment eligibility changed.
     */
    public function handle(Build $build, User $approver, ?string $note = null): ?Build
    {
        return DB::transaction(function () use ($build, $approver, $note): ?Build {
            $locked = Build::query()
                ->whereKey($build->id)
                ->where('status', Build::STATUS_AWAITING_APPROVAL)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->repository->isDeploymentReady() || $this->gate->blockReason($locked->repository)) {
                return null;
            }

            $locked->update([
                'status' => Build::STATUS_QUEUED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'approval_note' => filled($note) ? trim($note) : null,
            ]);
            $this->notifications->acknowledge($locked);
            $this->activity->record($locked, $approver->id, 'deployment', $locked->trigger_source === Build::TRIGGER_PROMOTION
                ? 'Release promotion was approved.'
                : 'Deployment was approved.');

            return $locked;
        });
    }
}
