<?php

namespace App\Services;

use App\Models\Build;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationConfigurationCancellation
{
    public function __construct(private readonly ApplicationConfigurationResults $results) {}

    public function cancel(ConfigurationOperation $operation, User $user): ConfigurationOperation
    {
        $projectId = $operation->application->review->project_id;
        $operation = DB::transaction(function () use ($operation, $user, $projectId) {
            $project = ApplicationConfigurationLocks::project($projectId);
            $operation = ConfigurationOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $review = $operation->application->review;
            $user = User::query()->findOrFail($user->id);
            if ((int) $review->project_id !== (int) $project->id
                || (int) $project->organization_id !== (int) $user->current_organization_id
                || ! $project->organization->permits($user, 'manage')) {
                throw new AuthorizationException;
            }
            if ($operation->status === 'canceled') {
                return $operation;
            }
            $build = Build::query()->whereKey($operation->build_id)->lockForUpdate()->first();
            if (in_array($operation->status, ['succeeded', 'failed'], true)
                || ($build && ! in_array($build->status, [Build::STATUS_QUEUED, Build::STATUS_AWAITING_APPROVAL], true))
                || (! $build && $operation->started_at)) {
                throw ValidationException::withMessages(['operation' => 'Only an operation that has not started remote execution can be canceled here. Use the deployment controls for a running build.']);
            }
            if ($build) {
                $updated = Build::query()->whereKey($build->id)->whereIn('status', [Build::STATUS_QUEUED, Build::STATUS_AWAITING_APPROVAL])
                    ->update(['status' => Build::STATUS_CANCELED, 'finished_at' => now(), 'failure_message' => 'Configuration deployment canceled before remote execution.']);
                if ($updated !== 1) {
                    throw ValidationException::withMessages(['operation' => 'The deployment started while cancellation was requested. Use its deployment controls.']);
                }
            }
            $operation->update(['status' => 'canceled', 'failure_code' => 'operation_canceled', 'available_at' => null, 'completed_at' => now()]);

            return $operation;
        }, 5);
        $this->results->refresh($operation->application);
        ConfigurationApplication::query()->whereHas('referencedOperations', fn ($query) => $query->where('configuration_operations.id', $operation->id))
            ->each(fn ($application) => $this->results->refresh($application));

        return $operation->fresh();
    }
}
