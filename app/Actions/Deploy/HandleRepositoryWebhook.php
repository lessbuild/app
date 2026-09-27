<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Data\Deploy\VerifiedRepositoryWebhook;
use App\Models\Build;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Services\Deploy\Deployments;
use App\Services\Deploy\RepositoryChangeImpactEvaluator;
use Illuminate\Support\Facades\DB;

final class HandleRepositoryWebhook
{
    public function __construct(private readonly Deployments $deployments, private readonly RepositoryChangeImpactEvaluator $impact) {}

    /**
     * Record a verified push to the branch and deploy it: once per delivery ID, skipped when no deployable path changed,
     * and held as pending while another deploy to the website runs (it follows when that finishes).
     *
     * @return string duplicate, unavailable, skipped, pending or queued
     */
    public function handle(Repository $repository, VerifiedRepositoryWebhook $webhook): string
    {
        return DB::transaction(function () use ($repository, $webhook): string {
            $locked = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            if (RepositoryWebhookDelivery::query()->where('repository_id', $locked->id)->where('delivery_id', $webhook->deliveryId)->exists()) {
                return 'duplicate';
            }
            $delivery = new RepositoryWebhookDelivery;
            $delivery->forceFill([
                'repository_id' => $locked->id, 'delivery_id' => $webhook->deliveryId, 'status' => 'received', 'revision' => $webhook->revision,
                'commit_message' => $webhook->commitMessage, 'changed_paths' => $webhook->changedPaths,
            ])->save();
            $locked->forceFill(['webhook_last_received_at' => now()])->save();
            $status = match (true) {
                ! $locked->isDeploymentReady() => 'unavailable',
                $this->impact->evaluate($locked, $webhook->changedPaths)->isUnaffected() => 'skipped',
                default => null,
            };
            if ($status !== null) {
                $delivery->forceFill(['status' => $status])->save();

                return $status;
            }
            $build = $this->deployments->blockReason($locked) !== null ? null : $this->deployments->queue($locked, ['trigger_source' => 'webhook', 'revision' => $webhook->revision, 'commit_message' => $webhook->commitMessage, 'changed_paths' => $webhook->changedPaths]);
            if ($build === null) {
                $locked->forceFill(['webhook_pending' => true, 'webhook_pending_revision' => $webhook->revision, 'webhook_pending_commit_message' => $webhook->commitMessage])->save();
                $delivery->forceFill(['status' => 'pending'])->save();

                return 'pending';
            }
            $delivery->forceFill(['status' => 'queued', 'build_id' => $build->id])->save();

            return $build->status === Build::STATUS_AWAITING_APPROVAL ? 'awaiting_approval' : 'queued';
        });
    }
}
