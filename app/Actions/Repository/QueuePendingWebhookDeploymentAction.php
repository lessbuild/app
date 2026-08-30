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

            $active = $locked->builds()->whereIn('status', Build::ACTIVE_STATUSES)->exists();
            if ($active) {
                return null;
            }

            $revision = $locked->webhook_pending_revision;
            $commitMessage = $locked->webhook_pending_commit_message;
            $locked->update([
                'webhook_pending' => false,
                'webhook_pending_revision' => null,
                'webhook_pending_commit_message' => null,
            ]);
            if (! $locked->isDeploymentReady()) {
                return null;
            }

            $locked->update(['setup_stage' => 0]);
            $locked->webhookDeliveries()
                ->where('status', RepositoryWebhookDelivery::STATUS_PENDING)
                ->update(['status' => RepositoryWebhookDelivery::STATUS_QUEUED]);

            return $locked->builds()->create([
                'status' => Build::STATUS_QUEUED,
                'trigger_source' => Build::TRIGGER_WEBHOOK,
                'revision' => $revision,
                'commit_message' => $commitMessage,
            ]);
        });

        if ($build) {
            DB::afterCommit(fn () => PublishRepositoryJob::dispatch($build));
        }

        return $build;
    }
}
