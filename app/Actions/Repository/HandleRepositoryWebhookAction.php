<?php

namespace App\Actions\Repository;

use App\Data\RepositoryWebhookResult;
use App\Data\VerifiedRepositoryWebhook;
use App\Models\Build;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Website;
use App\Services\DeploymentGate;
use App\Services\DeploymentRequest;
use Illuminate\Support\Facades\DB;

class HandleRepositoryWebhookAction
{
    /**
     * Coordinate webhook receipt deduplication, deployment admission, and pending delivery dispatch.
     *
     * @param  QueuePendingWebhookDeploymentAction  $queuePendingDeployment  Action that drains an eligible retained webhook revision after website capacity becomes available.
     * @param  DeploymentRequest  $deployments  Service that persists deployment requests and dispatches eligible builds.
     * @param  DeploymentGate  $gate  Deployment lock and scheduling-window policy evaluator.
     */
    public function __construct(
        private readonly QueuePendingWebhookDeploymentAction $queuePendingDeployment,
        private readonly DeploymentRequest $deployments,
        private readonly DeploymentGate $gate,
    ) {}

    /**
     * Record a verified delivery once and either queue its deployment or retain its revision pending deployment availability.
     *
     * @param  Repository  $repository  Repository addressed by the verified webhook.
     * @param  VerifiedRepositoryWebhook  $webhook  Authenticated delivery identity, source revision, and commit details.
     * @return RepositoryWebhookResult The duplicate, unavailable, pending, or queued disposition, including the build when one was created.
     */
    public function handle(Repository $repository, VerifiedRepositoryWebhook $webhook): RepositoryWebhookResult
    {
        $result = DB::transaction(function () use ($repository, $webhook): RepositoryWebhookResult {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            $locked = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            $inserted = DB::table('repository_webhook_deliveries')->insertOrIgnore([
                'repository_id' => $locked->id,
                'delivery_id' => $webhook->deliveryId,
                'revision' => $webhook->revision,
                'commit_message' => $webhook->commitMessage,
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

            if ((int) $locked->website_id !== (int) $website->id
                || $website->hasActiveDeployment()
                || $this->gate->blockReason($locked)) {
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
                'trigger_source' => Build::TRIGGER_WEBHOOK,
                'revision' => $webhook->revision,
                'commit_message' => $webhook->commitMessage,
                ...$this->deployments->attributes($locked),
            ]);
            $delivery->update([
                'status' => RepositoryWebhookDelivery::STATUS_QUEUED,
                'build_id' => $build->id,
            ]);

            return new RepositoryWebhookResult(RepositoryWebhookResult::QUEUED, $build);
        });

        if ($result->build) {
            $this->deployments->dispatch($result->build);
        } elseif ($result->status === RepositoryWebhookResult::PENDING) {
            $this->queuePendingDeployment->handle($repository->fresh());
        }

        return $result;
    }
}
