<?php

namespace App\Actions\Repository;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use Illuminate\Support\Facades\DB;

class QueuePendingWebhookDeploymentAction
{
    public function handle(Repository $repository): ?Build
    {
        $build = DB::transaction(function () use ($repository): ?Build {
            $locked = Repository::query()->lockForUpdate()->find($repository->id);
            if (! $locked?->webhook_enabled || ! $locked->webhook_pending) {
                return null;
            }

            $active = $locked->builds()->whereIn('status', [
                Build::STATUS_QUEUED,
                Build::STATUS_DEPLOYING,
                Build::STATUS_RUNNING,
            ])->exists();
            if ($active) {
                return null;
            }

            $locked->update(['webhook_pending' => false]);
            if (! $locked->isDeploymentReady()) {
                return null;
            }

            $locked->update(['setup_stage' => 0]);
            $locked->webhookDeliveries()
                ->where('status', RepositoryWebhookDelivery::STATUS_PENDING)
                ->update(['status' => RepositoryWebhookDelivery::STATUS_QUEUED]);

            return $locked->builds()->create(['status' => Build::STATUS_QUEUED]);
        });

        if ($build) {
            DB::afterCommit(fn () => PublishRepositoryJob::dispatch($build));
        }

        return $build;
    }
}
