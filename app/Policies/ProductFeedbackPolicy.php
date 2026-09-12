<?php

namespace App\Policies;

use App\Models\ProductFeedback;
use App\Models\User;

class ProductFeedbackPolicy
{
    /**
     * Allow a member of the currently selected workspace to submit private feedback.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'view') ?? false;
    }

    /**
     * Allow a workspace manager to review feedback belonging to the selected workspace.
     */
    public function review(User $user, ProductFeedback $feedback): bool
    {
        return $this->manages($user, $feedback);
    }

    /**
     * Allow a workspace manager to see the review controls for selected-workspace feedback.
     */
    public function reviewAny(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'manage') ?? false;
    }

    /**
     * Allow the submitter or a workspace manager to remove selected-workspace feedback.
     */
    public function delete(User $user, ProductFeedback $feedback): bool
    {
        if ((int) $feedback->organization_id !== (int) $user->current_organization_id) {
            return false;
        }

        return (int) $feedback->user_id === (int) $user->id || $this->manages($user, $feedback);
    }

    private function manages(User $user, ProductFeedback $feedback): bool
    {
        return (int) $feedback->organization_id === (int) $user->current_organization_id
            && ($feedback->organization?->permits($user, 'manage') ?? false);
    }
}
