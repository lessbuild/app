<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Enums\AlertMetric;
use App\Http\Requests\Monitoring\AlertRuleRequest;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class CreateAlertRuleController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectAlertRulesQuery $rules, Entitlements $entitlements): View
    {
        Gate::authorize('manageService', [$project, 'monitoring']);

        return view('monitoring.rule-form', [
            'overview' => $overview->handle($project, $user),
            'rule' => null,
            'metrics' => AlertMetric::cases(),
            'windows' => AlertRuleRequest::WINDOWS,
            'objectives' => $rules->objectives($project),
            'series' => MetricSeries::query()->whereIn('environment_id', $project->environments()->select('id'))->orderBy('name')->limit(500)->get(),
            'plan' => $entitlements->for($project->account),
        ]);
    }
}
