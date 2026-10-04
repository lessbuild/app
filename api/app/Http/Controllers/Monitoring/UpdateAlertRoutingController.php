<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\UpdateAlertRouting;
use App\Http\Requests\Monitoring\AlertRoutingRequest;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateAlertRoutingController
{
    /**
     * Save where a rule sends alerts.
     *
     * @param  AlertRoutingRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertRule  $rule
     * @param  UpdateAlertRouting  $update
     * @return JsonResponse
     */
    public function __invoke(AlertRoutingRequest $request, #[CurrentUser] User $user, Project $project, AlertRule $rule, UpdateAlertRouting $update): JsonResponse
    {
        $update->handle($rule, $user, $request->routing());

        return response()->json(['redirect' => route('monitoring.rules.show', [$project, $rule->id], false), 'message' => __('Alert routing saved.')]);
    }
}
