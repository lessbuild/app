<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ApplyConfigurationReview;
use App\Models\ConfigurationReview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ApplyConfigurationReviewController
{
    /**
     * Apply a configuration review and shows the application's progress.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ConfigurationReview  $review
     * @param  ApplyConfigurationReview  $apply
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ConfigurationReview $review, ApplyConfigurationReview $apply): JsonResponse
    {
        $application = $apply->handle($user, $review);

        return response()->json(['redirect' => route('deploy.configuration.applications.show', [$project, $application->id], false), 'message' => __('Configuration applied.')]);
    }
}
