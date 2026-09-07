<?php

namespace App\Services;

use App\Models\Build;
use App\Models\ConfigurationOperation;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApplicationConfigurationDelivery
{
    /**
     * Bind build reservation and queue delivery for durable configuration intents.
     *
     * @param  ApplicationConfigurationBuilds  $builds  Prepares or reuses the single build associated with an operation.
     * @param  DeploymentRequest  $deployments  Dispatches the existing build through the normal deployment lifecycle.
     */
    public function __construct(
        private readonly ApplicationConfigurationBuilds $builds,
        private readonly DeploymentRequest $deployments,
    ) {}

    /**
     * Lease an eligible operation and enqueue its reserved build outside the transaction.
     *
     * @param  ConfigurationOperation  $operation  The durable operation to prepare, claim and deliver.
     * @return void Preparation may update operation/build state before a lease is checked; claimed delivery records enqueue or failure outcomes.
     */
    public function deliver(ConfigurationOperation $operation): void
    {
        $build = $this->builds->prepare($operation);
        if (! $build) {
            return;
        }
        $projectId = $operation->application->review->project_id;
        $claimed = DB::transaction(function () use ($operation, $build, $projectId): int|false {
            ApplicationConfigurationLocks::project($projectId);
            $operation = ConfigurationOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if (in_array($operation->status, ['delivered', 'succeeded', 'failed', 'canceled'], true)
                || (in_array($operation->status, ['delivering', 'delivery_failed'], true) && $operation->available_at?->isFuture())) {
                return false;
            }
            $build->refresh();
            if ($build->status === Build::STATUS_AWAITING_APPROVAL) {
                $operation->update(['status' => 'awaiting_approval']);

                return false;
            }
            if ($build->status !== Build::STATUS_QUEUED) {
                // The existing build lifecycle owns remote execution and terminal state.
                $operation->update(['status' => 'delivered']);

                return false;
            }
            $operation->update(['status' => 'delivering', 'available_at' => now()->addMinutes(5),
                'failure_code' => null, 'attempts' => $operation->attempts + 1]);

            return $operation->attempts;
        }, 5);
        if (! $claimed) {
            return;
        }
        try {
            // Outside the transaction: queue drivers may execute synchronously.
            // Expired delivery leases can enqueue the same build again after a crash;
            // PublishRepositoryJob's queued-to-deploying compare-and-set prevents
            // duplicate remote execution for that build.
            $this->deployments->dispatch($build);
            ConfigurationOperation::query()->whereKey($operation->id)->where('status', 'delivering')
                ->where('attempts', $claimed)
                ->update(['status' => 'delivered', 'available_at' => null]);
        } catch (Throwable) {
            ConfigurationOperation::query()->whereKey($operation->id)->where('status', 'delivering')
                ->where('attempts', $claimed)
                ->update(['status' => 'delivery_failed', 'failure_code' => 'queue_delivery_failed', 'available_at' => now()->addMinute()]);
        }
    }
}
