<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\ConfigurationReview;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;

class ConfigurationReviewPolicy
{
    /**
     * Allow managers in the review's current workspace to inspect its saved intent.
     */
    public function view(User $user, ConfigurationReview $review): bool
    {
        $project = $review->project;

        return $project !== null
            && app(DeployerProjectAccess::class)->project($user, $project)
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
