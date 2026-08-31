<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class CancelQueuedDeploymentAction
{
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
                ->where('status', Build::STATUS_QUEUED)
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
