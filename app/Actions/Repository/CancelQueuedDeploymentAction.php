<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class CancelQueuedDeploymentAction
{
    /**
     * Lock the website and build before canceling a queued deployment or pending approval; clear its remote process metadata.
     *
     * @param  Build  $build  Build record whose persisted deployment state and relationships are used by this operation.
     * @return bool Whether the build was still eligible and was canceled; false when its website is gone or its state changed.
     */
    public function handle(Build $build): bool
    {
        $websiteId = $build->repository()->value('website_id');

        return DB::transaction(function () use ($build, $websiteId): bool {
            $website = Website::query()->lockForUpdate()->find($websiteId);
            if (! $website) {
                return false;
            }

            $locked = Build::query()
                ->whereKey($build->id)
                ->whereIn('status', [Build::STATUS_QUEUED, Build::STATUS_AWAITING_APPROVAL])
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                return false;
            }

            $locked->update([
                'status' => Build::STATUS_CANCELED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'finished_at' => now(),
                'failure_message' => null,
            ]);

            return true;
        });
    }
}
