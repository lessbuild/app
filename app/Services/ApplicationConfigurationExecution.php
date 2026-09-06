<?php

namespace App\Services;

use App\Models\Build;
use App\Models\ConfigurationOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplicationConfigurationExecution
{
    public function __construct(private readonly ApplicationConfigurationBuilds $builds) {}

    /** null means an ordinary build; configuration builds recheck gates at remote start. */
    public function claim(Build $build): ?bool
    {
        $origin = $build->environment_payload['configuration_operation_id'] ?? null;
        $operation = Schema::hasTable('configuration_operations')
            ? ConfigurationOperation::query()->where('build_id', $build->id)->first()
            : null;
        if (! $operation || ($origin !== null && (! is_int($origin) || $origin !== (int) $operation->id))) {
            if ($origin === null) {
                return null;
            }
            // The immutable origin survives review/project/user cascades. Retire
            // only builds that have not started; never fall back to ordinary execution.
            Build::query()->whereKey($build->id)
                ->whereIn('status', [Build::STATUS_QUEUED, Build::STATUS_AWAITING_APPROVAL])
                ->update(['status' => Build::STATUS_CANCELED, 'finished_at' => now(),
                    'failure_message' => 'Configuration deployment authorization is no longer available.']);

            return false;
        }
        $projectId = $operation->application->review->project_id;

        return DB::transaction(function () use ($operation, $build, $projectId): bool {
            ApplicationConfigurationLocks::project($projectId);
            $prepared = $this->builds->prepare($operation);
            if (! $prepared || $prepared->id !== $build->id) {
                return false;
            }
            if ($prepared->status === Build::STATUS_AWAITING_APPROVAL) {
                $operation->update(['status' => 'awaiting_approval']);

                return false;
            }

            $releaseName = $prepared->releaseIdentifier();

            return Build::query()->whereKey($build->id)->where('status', Build::STATUS_QUEUED)
                ->update([
                    'status' => Build::STATUS_DEPLOYING,
                    'started_at' => now(), 'last_heartbeat_at' => now(),
                    'remote_process_path' => "/tmp/lessbuild-deployment-{$build->id}.sh",
                    'failure_message' => null, 'release_name' => $releaseName,
                    'release_path' => "/var/www/{$prepared->repository->website->deployment_slug}/releases/{$releaseName}",
                ]) === 1;
        }, 5);
    }
}
