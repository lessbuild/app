<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;
use App\Services\Billing\Entitlements;
use Illuminate\Auth\Access\Response;

final class ServiceLevelObjectivePolicy
{
    use ManagesMonitoring;

    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project) && $user->hasVerifiedEmail();
    }

    public function update(User $user, ServiceLevelObjective $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->environment->project) && $user->hasVerifiedEmail();
    }

    /** CSV reports are a Team and Scale feature. */
    public function export(User $user, ServiceLevelObjective $record): Response
    {
        if (! $user->can('useService', [$record->environment->project, 'monitoring'])) {
            return Response::deny();
        }

        return app(Entitlements::class)->for($record->environment->project->account)->has('monitoring.slo_reports')
            ? Response::allow()
            : Response::deny(__('SLO reports come with Monitoring Team and Scale.'));
    }

    public function delete(User $user, ServiceLevelObjective $record): bool
    {
        return $this->update($user, $record);
    }
}
