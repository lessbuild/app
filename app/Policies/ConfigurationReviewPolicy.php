<?php

namespace App\Policies;

use App\Models\ConfigurationReview;
use App\Models\User;

class ConfigurationReviewPolicy
{
    /**
     * Allow managers in the review's current workspace to inspect its saved intent.
     */
    public function view(User $user, ConfigurationReview $review): bool
    {
        $project = $review->project;

        return $project !== null
            && (int) $project->organization_id === (int) $user->current_organization_id
            && $project->organization->permits($user, 'manage');
    }

    /**
     * Allow only the review author, still authorized as a manager, to apply the saved intent.
     */
    public function apply(User $user, ConfigurationReview $review): bool
    {
        return $this->view($user, $review) && (int) $review->requested_by === (int) $user->id;
    }

    /**
     * Allow only the review author, still authorized as a manager, to retry its failed operation.
     */
    public function retry(User $user, ConfigurationReview $review): bool
    {
        return $this->apply($user, $review);
    }
}
