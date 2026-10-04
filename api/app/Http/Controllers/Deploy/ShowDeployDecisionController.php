<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/builds/{build}/decide`. */
final class ShowDeployDecisionController
{
    /**
     * Return a deploy waiting for approval (the page an approval notification links to), and whether the person may
     * decide it, or why not.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, ProjectOverviewQuery $overview): JsonResponse
    {
        $build->loadMissing(['repository', 'environment', 'requester']);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'build' => [
                'id' => $build->id,
                'status' => $build->status,
                'awaitingApproval' => $build->status === Build::STATUS_AWAITING_APPROVAL,
                'repository' => $build->repository->name,
                'branch' => $build->repository->branch,
                'environment' => $build->environment?->name,
                'requester' => $build->requester?->name,
                'revision' => $build->revision === null ? null : substr($build->revision, 0, 12),
                'commitMessage' => $build->commit_message,
            ],
            'canApprove' => $user->can('approve', $build),
            'reason' => $build->requested_by === $user->id ? __('Someone else has to approve your deploy.') : __('You can’t approve deploys here.'),
        ]);
    }
}
