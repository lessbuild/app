<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\ConfigurationReview;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/configuration/reviews/{review}`. */
final class ShowConfigurationReviewController
{
    /**
     * Return a configuration review: its planned changes, until when it can be applied, and whether this person may.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ConfigurationReview  $review
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ConfigurationReview $review, ProjectOverviewQuery $overview): JsonResponse
    {
        $review->load(['requester', 'application']);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'review' => [
                'id' => $review->id,
                'requester' => $review->requester->name,
                'expiresAt' => $review->expires_at->toIso8601String(),
                'expired' => $review->expires_at->isPast(),
                'plan' => $review->summary,
                'applicationId' => $review->application?->id,
            ],
            'canApply' => $review->requested_by === $user->id,
        ]);
    }
}
