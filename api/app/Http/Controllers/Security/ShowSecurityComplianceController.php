<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Project;
use App\Models\SecurityAccessReview;
use App\Models\SecurityFinding;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowSecurityComplianceController
{
    /**
     * Show the compliance page: how the project stands (last access review, open serious findings, findings resolved
     * this year) and whether the evidence pack can be downloaded.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, Entitlements $entitlements): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'lastReviewAt' => SecurityAccessReview::query()->where('project_id', $project->id)->latest('id')->first()?->created_at?->toIso8601String(),
            'openSerious' => SecurityFinding::query()->where('project_id', $project->id)->where('status', 'open')->whereIn('severity', ['critical', 'high'])->count(),
            'resolved' => SecurityFinding::query()->where('project_id', $project->id)->where('status', 'resolved')->where('resolved_at', '>=', now()->subYear())->count(),
            'included' => $entitlements->for($project->account)->has('security.compliance'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
