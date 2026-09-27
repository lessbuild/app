<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\ConfigurationReview;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowConfigurationReviewController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, ConfigurationReview $review, ProjectOverviewQuery $overview): View
    {
        return view('deploy.configuration-review', ['overview' => $overview->handle($project, $user), 'review' => $review->load(['requester', 'application']), 'canApply' => $review->requested_by === $user->id]);
    }
}
