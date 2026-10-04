<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\ConfigurationApplication;
use App\Models\Project;
use App\Models\User;
use App\Queries\Deploy\ConfigurationQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/configuration/applications/{application}`. */
final class ShowConfigurationApplicationController
{
    /**
     * Return what applying a review did: its receipt (status and the deploys it started) and whether the person may
     * retry a failed one.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ConfigurationApplication  $application
     * @param  ProjectOverviewQuery  $overview
     * @param  ConfigurationQuery  $configuration
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ConfigurationApplication $application, ProjectOverviewQuery $overview, ConfigurationQuery $configuration): JsonResponse
    {
        $application->load('review.requester');

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'application' => [
                'id' => $application->id,
                'reviewId' => $application->configuration_review_id,
                'requester' => $application->review->requester->name,
                'appliedAt' => $application->locally_applied_at?->toIso8601String(),
            ],
            'receipt' => $configuration->receipt($application),
            'canRetry' => $application->review->requested_by === $user->id,
        ]);
    }
}
