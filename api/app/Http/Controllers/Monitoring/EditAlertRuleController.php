<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertRuleFormQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class EditAlertRuleController
{
    /**
     * Describe the form for editing an alert rule, with its current settings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertRule  $rule
     * @param  ProjectOverviewQuery  $overview
     * @param  AlertRuleFormQuery  $form
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AlertRule $rule, ProjectOverviewQuery $overview, AlertRuleFormQuery $form): JsonResponse
    {
        return response()->json(['overview' => $overview->handle($project, $user), ...$form->handle($project, $rule)]);
    }
}
