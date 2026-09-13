<?php

namespace App\Policies;

use App\Models\ObservabilityInvestigationView;
use App\Models\User;

class ObservabilityInvestigationViewPolicy
{
    /**
     * Allow an account to open a non-expired view only through its current workspace and environment access.
     */
    public function view(User $user, ObservabilityInvestigationView $view): bool
    {
        $environment = $view->environment;

        return $environment !== null
            && ! $view->isExpired()
            && (int) $view->organization_id === (int) $user->current_organization_id
            && (int) $environment->project->organization_id === (int) $view->organization_id
            && $environment->project->organization->permits($user, 'view');
    }

    /** Allow the creator or a current workspace manager to remove a named view. */
    public function delete(User $user, ObservabilityInvestigationView $view): bool
    {
        $environment = $view->environment;

        return $environment !== null
            && (int) $view->organization_id === (int) $user->current_organization_id
            && (int) $environment->project->organization_id === (int) $view->organization_id
            && ((int) $view->created_by === (int) $user->id
                || $environment->project->organization->permits($user, 'manage'));
    }
}
