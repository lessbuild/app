<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveAlertRule;
use App\Http\Requests\Monitoring\AlertRuleRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreAlertRuleController
{
    /**
     * Create an alert rule; it starts checking within a minute.
     *
     * @param  AlertRuleRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveAlertRule  $save
     * @return JsonResponse
     */
    public function __invoke(AlertRuleRequest $request, #[CurrentUser] User $user, Project $project, SaveAlertRule $save): JsonResponse
    {
        $rule = $save->handle($project, $user, $request->validated());

        return response()->json(['redirect' => route('monitoring.rules.show', [$project, $rule->id], false), 'message' => __('Alert rule created. It starts checking within a minute.')]);
    }
}
