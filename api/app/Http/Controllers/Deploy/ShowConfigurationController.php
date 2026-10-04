<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\ConfigurationReview;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Queries\Deploy\ConfigurationQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/configuration`. */
final class ShowConfigurationController
{
    /**
     * Return what configuration documents can bind to (websites, repositories and secrets by ID), recent reviews,
     * the project's workflow document, and whether the person may change it.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ConfigurationQuery  $configuration
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ConfigurationQuery $configuration): JsonResponse
    {
        $page = $configuration->page($project);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'websites' => $page['websites']->map(fn (Website $website): array => ['id' => $website->id, 'label' => $website->name])->values(),
            'repositories' => $page['repositories']->map(fn (Repository $repository): array => ['id' => $repository->id, 'label' => $repository->name.' → '.$repository->website->name])->values(),
            'secrets' => $page['secrets']->map(fn (EnvironmentVariable $secret): array => ['id' => $secret->id, 'label' => $secret->key.' ('.$secret->environment->name.')'])->values(),
            'reviews' => $page['reviews']->map(fn (ConfigurationReview $review): array => [
                'id' => $review->id,
                'requester' => $review->requester->name,
                'createdAt' => $review->created_at?->toIso8601String(),
                'changes' => count($review->summary['changes']),
                'applicationId' => $review->application?->id,
                'applicationStatus' => $review->application?->status,
                'expired' => $review->expires_at->isPast(),
            ])->values(),
            'workflow' => $project->workflow_document,
            'canManage' => $user->can('manageDeploy', $project),
        ]);
    }
}
