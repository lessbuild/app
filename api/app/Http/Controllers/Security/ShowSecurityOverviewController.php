<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Data\Security\FindingRow;
use App\Data\Security\SecurityCheck;
use App\Models\Environment;
use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Security\SecurityOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowSecurityOverviewController
{
    /**
     * Show the project's Security overview: its score, open findings by severity, the checks and when they last ran,
     * the deploy gate for each environment, and the most serious open findings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  SecurityOverviewQuery  $security
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, SecurityOverviewQuery $security, Entitlements $entitlements): JsonResponse
    {
        $summary = $security->handle($project);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'score' => $summary['score'],
            'grade' => $summary['grade'],
            'trend' => $summary['trend'],
            'intervalHours' => $summary['intervalHours'],
            'bySeverity' => array_map(fn (string $severity): array => [
                'severity' => $severity,
                'label' => __(SecurityFinding::SEVERITIES[$severity]['label']),
                'count' => $summary['bySeverity'][$severity] ?? 0,
            ], ['critical', 'high', 'medium']),
            'checks' => array_map(SecurityCheck::from(...), $summary['checks']),
            'recent' => $summary['recent']->map(FindingRow::from(...))->values(),
            'environments' => $project->hasService('deploy')
                ? $project->environments()->orderBy('name')->get()->map(fn (Environment $environment): array => ['id' => $environment->id, 'name' => $environment->name, 'gate' => $environment->security_gate])->values()
                : [],
            'gateIncluded' => $entitlements->for($project->account)->has('security.deploy_gate'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
