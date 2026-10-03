<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\ConfigurationApplication;
use App\Models\Project;
use App\Models\User;
use App\Queries\Deploy\ConfigurationQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowConfigurationApplicationController
{
    /**
     * Show an applied configuration's progress. Only the person who asked for the review may retry its operations.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ConfigurationApplication  $application
     * @param  ProjectOverviewQuery  $overview
     * @param  ConfigurationQuery  $configuration
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ConfigurationApplication $application, ProjectOverviewQuery $overview, ConfigurationQuery $configuration): View
    {
        return view('deploy.configuration-application', [
            'overview' => $overview->handle($project, $user), 'application' => $application->load('review.requester'),
            'receipt' => $configuration->receipt($application), 'canRetry' => $application->review->requested_by === $user->id,
        ]);
    }
}
