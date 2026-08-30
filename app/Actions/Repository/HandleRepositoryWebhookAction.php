<?php

namespace App\Actions\Repository;

use App\Data\RepositoryWebhookResult;
use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use Illuminate\Support\Facades\DB;

class HandleRepositoryWebhookAction
{
    public function handle(Repository $repository, string $deliveryId): RepositoryWebhookResult
    {
        $result = DB::transaction(function () use ($repository, $deliveryId): RepositoryWebhookResult {
            $locked = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            $inserted = DB::table('repository_webhook_deliveries')->insertOrIgnore([
                'repository_id' => $locked->id,
                'delivery_id' => $deliveryId,
                'status' => RepositoryWebhookDelivery::STATUS_RECEIVED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                return new RepositoryWebhookResult(RepositoryWebhookResult::DUPLICATE);
            }

            $delivery = $locked->webhookDeliveries()->where('delivery_id', $deliveryId)->sole();

            $locked->update(['webhook_last_received_at' => now()]);
            if (! $locked->isDeploymentReady()) {
                $delivery->update(['status' => RepositoryWebhookDelivery::STATUS_UNAVAILABLE]);

                return new RepositoryWebhookResult(RepositoryWebhookResult::UNAVAILABLE);
            }

            $active = $locked->builds()->whereIn('status', [
                Build::STATUS_QUEUED,
                Build::STATUS_DEPLOYING,
                Build::STATUS_RUNNING,
            ])->exists();
            if ($active) {
                $locked->update(['webhook_pending' => true]);
                $delivery->update(['status' => RepositoryWebhookDelivery::STATUS_PENDING]);

                return new RepositoryWebhookResult(RepositoryWebhookResult::PENDING);
            }

            $locked->update(['setup_stage' => 0]);
            $build = $locked->builds()->create(['status' => Build::STATUS_QUEUED]);
            $delivery->update(['status' => RepositoryWebhookDelivery::STATUS_QUEUED]);

            return new RepositoryWebhookResult(RepositoryWebhookResult::QUEUED, $build);
        });

        if ($result->build) {
            PublishRepositoryJob::dispatch($result->build);
        }

        return $result;
    }
}
