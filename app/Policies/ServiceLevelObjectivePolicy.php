<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;
use App\Services\Billing\Entitlements;

final class ServiceLevelObjectivePolicy
{
    use ManagesMonitoring;

    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project);
    }

    public function update(User $user, ServiceLevelObjective $record): bool
    {
        return $this->managesMonitoring($user, $record->environment->project);
    }

    /** CSV reports are a Team and Scale feature. */
    public function export(User $user, ServiceLevelObjective $record): bool
    {
        return $user->can('useService', [$record->environment->project, 'monitoring'])
            && app(Entitlements::class)->for($record->environment->project->account)->has('monitoring.slo_reports');
    }

    public function delete(User $user, ServiceLevelObjective $record): bool
    {
        return $this->update($user, $record);
    }
}
