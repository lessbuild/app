<?php

namespace App\Actions\Repository;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class QueuePendingWebhookDeploymentAction
{
    public function handle(Repository $repository): ?Build
    {
        $build = DB::transaction(function () use ($repository): ?Build {
            $website = Website::query()->lockForUpdate()->find($repository->website_id);
            if (! $website || $website->hasActiveDeployment()) {
                return null;
            }

            $pendingRepositories = Repository::query()
                ->where('website_id', $website->id)
                ->where('webhook_enabled', true)
                ->where('webhook_pending', true)
                ->orderBy('webhook_last_received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($pendingRepositories as $locked) {
                $revision = $locked->webhook_pending_revision;
                $commitMessage = $locked->webhook_pending_commit_message;
                $locked->update([
                    'webhook_pending' => false,
                    'webhook_pending_revision' => null,
                    'webhook_pending_commit_message' => null,
                ]);
                if (! $locked->isDeploymentReady()) {
                    $locked->webhookDeliveries()
                        ->where('status', RepositoryWebhookDelivery::STATUS_PENDING)
                        ->update(['status' => RepositoryWebhookDelivery::STATUS_UNAVAILABLE]);

                    continue;
                }

                $locked->update(['setup_stage' => 0]);
                $pendingDeliveries = $locked->webhookDeliveries()
                    ->where('status', RepositoryWebhookDelivery::STATUS_PENDING)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $build = $locked->builds()->create([
                    'status' => Build::STATUS_QUEUED,
                    'trigger_source' => Build::TRIGGER_WEBHOOK,
                    'revision' => $revision,
                    'commit_message' => $commitMessage,
                ]);
                $latestDelivery = $pendingDeliveries->last();
                if ($latestDelivery) {
                    $locked->webhookDeliveries()
                        ->where('status', RepositoryWebhookDelivery::STATUS_PENDING)
                        ->where('id', '!=', $latestDelivery->id)
                        ->update(['status' => RepositoryWebhookDelivery::STATUS_SUPERSEDED]);
                    $latestDelivery->update([
                        'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
                        'build_id' => $build->id,
                    ]);
                }

                return $build;
            }

            return null;
        });

        if ($build) {
            DB::afterCommit(fn () => PublishRepositoryJob::dispatch($build));
        }

        return $build;
    }
}
