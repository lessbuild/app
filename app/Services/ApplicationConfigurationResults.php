<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use Illuminate\Support\Facades\DB;

class ApplicationConfigurationResults
{
    /**
     * Synchronize recorded build outcomes, never infer success from queue delivery.
     *
     * @param  ConfigurationApplication  $application  The receipt whose operation outcomes are refreshed.
     * @return ConfigurationApplication The locked receipt with its current aggregate status.
     */
    public function refresh(ConfigurationApplication $application): ConfigurationApplication
    {
        $projectId = $application->review->project_id;

        return DB::transaction(function () use ($application, $projectId): ConfigurationApplication {
            ApplicationConfigurationLocks::project($projectId);
            $application = ConfigurationApplication::query()->lockForUpdate()->findOrFail($application->id);
            $operations = $application->relatedOperations()
                ->with('build')
                ->withExists('retry')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($operations as $operation) {
                $build = $operation->build;
                if (! $build && $operation->started_at && ! in_array($operation->status, ['succeeded', 'failed', 'canceled'], true)) {
                    $operation->update(['status' => 'failed', 'failure_code' => 'build_missing', 'completed_at' => now(), 'available_at' => null]);
                }
                $buildStatus = $build?->statusEnum();
                if ($buildStatus?->isTerminal() !== true) {
                    continue;
                }
                $status = match ($buildStatus) {
                    BuildStatus::Succeeded => 'succeeded',
                    BuildStatus::Canceled, BuildStatus::Rejected => 'canceled',
                    default => 'failed',
                };
                if ($operation->status !== $status || ! $operation->completed_at) {
                    $operation->update(['status' => $status, 'completed_at' => $build->finished_at ?? now(),
                        'available_at' => null, 'failure_code' => $status === 'succeeded' ? null : 'deployment_'.$status]);
                }
            }
            $statuses = $operations->filter(fn (ConfigurationOperation $operation): bool => ! $operation->retry_exists)->pluck('status');
            $status = match (true) {
                $operations->isEmpty() => 'locally_applied',
                $statuses->contains('failed'), $statuses->contains('canceled') => 'remote_failed',
                $statuses->every(fn (string $value): bool => $value === 'succeeded') => 'succeeded',
                $statuses->contains('blocked'), $statuses->contains('delivery_failed') => 'needs_attention',
                $statuses->contains('awaiting_approval') => 'awaiting_approval',
                $statuses->contains('delivered') => 'deploying',
                default => 'awaiting_dispatch',
            };
            if ($application->status !== $status) {
                $application->update(['status' => $status]);
            }

            return $application;
        }, 5);
    }
}
