<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\BuildApprovalNotifications;
use Illuminate\Support\Facades\DB;

class RejectBuildAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly BuildApprovalNotifications $notifications,
    ) {}

    /**
     * Atomically reject a build that is still awaiting deployment review.
     *
     * @param  Build  $build  Build whose current approval state is being reviewed.
     * @param  User  $rejector  Authorized user whose decision is recorded.
     * @param  string|null  $note  Validated optional rejection note.
     * @return bool Whether the build was still awaiting review and was rejected.
     */
    public function handle(Build $build, User $rejector, ?string $note = null): bool
    {
        return DB::transaction(function () use ($build, $rejector, $note): bool {
            $locked = Build::query()
                ->whereKey($build->id)
                ->where('status', Build::STATUS_AWAITING_APPROVAL)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            $locked->update([
                'status' => Build::STATUS_REJECTED,
                'rejected_by' => $rejector->id,
                'rejected_at' => now(),
                'finished_at' => now(),
                'approval_note' => filled($note) ? trim($note) : null,
            ]);
            $this->notifications->acknowledge($locked);
            $this->activity->record($locked, $rejector->id, 'deployment', $locked->trigger_source === Build::TRIGGER_PROMOTION
                ? 'Release promotion was rejected.'
                : 'Deployment was rejected.');

            return true;
        });
    }
}
