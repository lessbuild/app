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

    /**
     * Determine whether the user can define an SLO: people who manage Monitoring in the project, with a verified email
     * since SLOs can page people.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project) && $user->hasVerifiedEmail();
    }

    /**
     * Determine whether the user can change an SLO: the same people as create, while it isn't archived.
     *
     * @param  User  $user
     * @param  ServiceLevelObjective  $record
     * @return bool
     */
    public function update(User $user, ServiceLevelObjective $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->environment->project) && $user->hasVerifiedEmail();
    }

    /**
     * Determine whether the user can download an SLO's CSV report, which is a Team and Scale feature.
     *
     * @param  User  $user
     * @param  ServiceLevelObjective  $record
     * @return Response
     */
    public function export(User $user, ServiceLevelObjective $record): Response
    {
        if (! $user->can('useService', [$record->environment->project, 'monitoring'])) {
            return Response::deny();
        }

        return app(Entitlements::class)->for($record->environment->project->account)->has('monitoring.slo_reports')
            ? Response::allow()
            : Response::deny(__('SLO reports come with Monitoring Team and Scale.'));
    }

    /**
     * Determine whether the user can archive an SLO, which the same people as update can.
     *
     * @param  User  $user
     * @param  ServiceLevelObjective  $record
     * @return bool
     */
    public function delete(User $user, ServiceLevelObjective $record): bool
    {
        return $this->update($user, $record);
    }
}
