<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\ObjectiveSummary;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\ServiceObjectiveBurnRate;
use App\Services\Monitoring\ServiceObjectiveReport;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowObjectiveController
{
    /**
     * Show an objective: compliance, error budget, good and bad requests, and (on plans with it) its burn rate.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ServiceLevelObjective  $objective
     * @param  ProjectOverviewQuery  $overview
     * @param  ServiceObjectiveReport  $reports
     * @param  ServiceObjectiveBurnRate  $burnRates
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ServiceLevelObjective $objective, ProjectOverviewQuery $overview, ServiceObjectiveReport $reports, ServiceObjectiveBurnRate $burnRates, Entitlements $entitlements): JsonResponse
    {
        $plan = $entitlements->for($project->account);
        $report = $reports->forObjective($objective);
        $burn = $plan->has('monitoring.slo_burn_rate') ? $burnRates->forObjective($objective) : null;

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'objective' => ObjectiveSummary::from($objective->loadMissing('environment.project'), $report),
            'report' => [
                'good' => $report['good'], 'bad' => $report['bad'], 'observed' => $report['observed'], 'unknown' => $report['unknown'],
                'from' => $report['from']->toIso8601String(), 'until' => $report['until']->toIso8601String(),
            ],
            'burnRate' => $burn === null ? null : [
                'status' => $burn['status'], 'label' => __($burn['label']), 'message' => __($burn['message']),
                'short' => $burn['short']['burn_rate'] ?? null, 'long' => $burn['long']['burn_rate'] ?? null,
            ],
            // The error budget left at the end of each of the last 14 days, for the chart.
            'history' => array_map(function (int $ago) use ($reports, $objective): array {
                $until = $ago === 0 ? CarbonImmutable::now('UTC') : CarbonImmutable::now('UTC')->subDays($ago)->endOfDay();

                return ['date' => $until->toDateString(), 'remaining' => $reports->forObjective($objective, $until)['budget_remaining']];
            }, range(13, 0)),
            'allowed' => $report['observed'] > 0 ? (int) floor($report['observed'] * (100 - (float) $report['target']) / 100) : null,
            'canExport' => $plan->has('monitoring.slo_reports'),
            'canManage' => $user->can('update', $objective),
        ]);
    }
}
