<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;

/** Anyone who can use Analytics on the project sees a site and exports its data; people who manage Analytics change it and its goals. */
final class AnalyticsSitePolicy
{
    /**
     * Adding an analytics site to a project: people who manage Analytics there.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function create(User $user, Project $project): bool
    {
        return $user->can('manageService', [$project, 'analytics']);
    }

    /**
     * Seeing a site's reports: people who may use Analytics in its project.
     *
     * @param  User  $user
     * @param  AnalyticsSite  $site
     * @return bool
     */
    public function view(User $user, AnalyticsSite $site): bool
    {
        return $user->can('useService', [$site->project, 'analytics']);
    }

    /**
     * Downloading a site's data, allowed to anyone who may see its reports.
     *
     * @param  User  $user
     * @param  AnalyticsSite  $site
     * @return bool
     */
    public function export(User $user, AnalyticsSite $site): bool
    {
        return $this->view($user, $site);
    }

    /**
     * Changing a site's domains, goals and settings: people who manage Analytics in its project.
     *
     * @param  User  $user
     * @param  AnalyticsSite  $site
     * @return bool
     */
    public function update(User $user, AnalyticsSite $site): bool
    {
        return $user->can('manageService', [$site->project, 'analytics']);
    }

    /**
     * Removing a site, allowed to the same people as update.
     *
     * @param  User  $user
     * @param  AnalyticsSite  $site
     * @return bool
     */
    public function delete(User $user, AnalyticsSite $site): bool
    {
        return $this->update($user, $site);
    }
}
