<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ApplyConfigurationReview;
use App\Models\ConfigurationReview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class ApplyConfigurationReviewController
{
    /**
     * Applies a configuration review and shows the application's progress.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ConfigurationReview $review, ApplyConfigurationReview $apply): RedirectResponse
    {
        $application = $apply->handle($user, $review);

        return to_route('deploy.configuration.applications.show', [$project, $application->id])->with('status', __('Configuration applied.'));
    }
}
