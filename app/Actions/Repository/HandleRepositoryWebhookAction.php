<?php

namespace App\Actions\Repository;

use App\Data\RepositoryWebhookResult;
use App\Data\VerifiedRepositoryWebhook;
use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class HandleRepositoryWebhookAction
{
    public function __construct(
        private readonly QueuePendingWebhookDeploymentAction $queuePendingDeployment,
    ) {}

    public function handle(Repository $repository, VerifiedRepositoryWebhook $webhook): RepositoryWebhookResult
    {
        $result = DB::transaction(function () use ($repository, $webhook): RepositoryWebhookResult {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            $locked = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            $inserted = DB::table('repository_webhook_deliveries')->insertOrIgnore([
                'repository_id' => $locked->id,
                'delivery_id' => $webhook->deliveryId,
                'status' => RepositoryWebhookDelivery::STATUS_RECEIVED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                return new RepositoryWebhookResult(RepositoryWebhookResult::DUPLICATE);
            }

            $delivery = $locked->webhookDeliveries()->where('delivery_id', $webhook->deliveryId)->sole();

            $locked->update(['webhook_last_received_at' => now()]);
            if (! $locked->isDeploymentReady()) {
                $delivery->update(['status' => RepositoryWebhookDelivery::STATUS_UNAVAILABLE]);

                return new RepositoryWebhookResult(RepositoryWebhookResult::UNAVAILABLE);
            }

            if ((int) $locked->website_id !== (int) $website->id || $website->hasActiveDeployment()) {
                $locked->update([
                    'webhook_pending' => true,
                    'webhook_pending_revision' => $webhook->revision,
                    'webhook_pending_commit_message' => $webhook->commitMessage,
                ]);
                $delivery->update(['status' => RepositoryWebhookDelivery::STATUS_PENDING]);

                return new RepositoryWebhookResult(RepositoryWebhookResult::PENDING);
            }

            $locked->update(['setup_stage' => 0]);
            $build = $locked->builds()->create([
                'status' => Build::STATUS_QUEUED,
                'trigger_source' => Build::TRIGGER_WEBHOOK,
                'revision' => $webhook->revision,
                'commit_message' => $webhook->commitMessage,
            ]);
            $delivery->update(['status' => RepositoryWebhookDelivery::STATUS_QUEUED]);

            return new RepositoryWebhookResult(RepositoryWebhookResult::QUEUED, $build);
        });

        if ($result->build) {
            PublishRepositoryJob::dispatch($result->build);
        } elseif ($result->status === RepositoryWebhookResult::PENDING) {
            $this->queuePendingDeployment->handle($repository->fresh());
        }

        return $result;
    }
}
