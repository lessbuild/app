<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Enums\AlertMetric;
use App\Http\Requests\Monitoring\AlertRuleRequest;
use App\Models\AlertRule;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class EditAlertRuleController
{
    /**
     * The alert rule form, filled in.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AlertRule $rule, ProjectOverviewQuery $overview, ProjectAlertRulesQuery $rules, Entitlements $entitlements): View
    {

        return view('monitoring.rule-form', [
            'overview' => $overview->handle($project, $user),
            'rule' => $rule,
            'metrics' => AlertMetric::cases(),
            'windows' => AlertRuleRequest::WINDOWS,
            'objectives' => $rules->objectives($project),
            'series' => MetricSeries::query()->whereIn('environment_id', $project->environments()->select('id'))->orderBy('name')->limit(500)->get(),
            'plan' => $entitlements->for($project->account),
        ]);
    }
}
