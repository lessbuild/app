<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\AlertDestination;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowAlertRuleController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, AlertRule $rule, ProjectOverviewQuery $overview, Entitlements $entitlements): View
    {

        return view('monitoring.rule', [
            'overview' => $overview->handle($project, $user),
            'rule' => $rule,
            'incidents' => $rule->incidents()->latest('opened_at')->latest('id')->limit(10)->get(),
            'destinations' => AlertDestination::query()->where('account_id', $project->account_id)->orderBy('name')->get(),
            'routes' => $rule->destinations()->get(),
            'escalations' => $rule->escalations()->get(),
            'escalationLimit' => $entitlements->for($project->account)->limit('monitoring.escalation_steps.max'),
            'canManage' => $user->can('update', $rule),
        ]);
    }
}
