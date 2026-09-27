<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowAlertRuleController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $rule, ProjectOverviewQuery $overview, ProjectAlertRulesQuery $rules, Entitlements $entitlements): View
    {
        $target = $rules->find($project, $rule, withArchived: true);

        return view('monitoring.rule', [
            'overview' => $overview->handle($project, $user),
            'rule' => $target,
            'incidents' => $target->incidents()->latest('opened_at')->latest('id')->limit(10)->get(),
            'destinations' => AlertDestination::query()->where('account_id', $project->account_id)->orderBy('name')->get(),
            'routes' => $target->destinations()->get(),
            'escalations' => $target->escalations()->get(),
            'escalationLimit' => $entitlements->for($project->account)->limit('monitoring.escalation_steps.max'),
            'canManage' => $user->can('manageService', [$project, 'monitoring']) && ! $target->trashed(),
        ]);
    }
}
