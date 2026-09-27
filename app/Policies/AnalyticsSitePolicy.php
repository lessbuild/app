<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;

/** Anyone who can use Analytics on the project sees a site and exports its data; people who manage Analytics change it and its goals. */
final class AnalyticsSitePolicy
{
    public function create(User $user, Project $project): bool
    {
        return $user->can('manageService', [$project, 'analytics']);
    }

    public function view(User $user, AnalyticsSite $site): bool
    {
        return $user->can('useService', [$site->project, 'analytics']);
    }

    public function export(User $user, AnalyticsSite $site): bool
    {
        return $this->view($user, $site);
    }

    public function update(User $user, AnalyticsSite $site): bool
    {
        return $user->can('manageService', [$site->project, 'analytics']);
    }

    public function delete(User $user, AnalyticsSite $site): bool
    {
        return $this->update($user, $site);
    }
}
