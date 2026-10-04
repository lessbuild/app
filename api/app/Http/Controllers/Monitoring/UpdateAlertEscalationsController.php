<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\UpdateAlertEscalations;
use App\Http\Requests\Monitoring\AlertEscalationsRequest;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateAlertEscalationsController
{
    /**
     * Save a rule's escalation steps.
     *
     * @param  AlertEscalationsRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertRule  $rule
     * @param  UpdateAlertEscalations  $update
     * @return JsonResponse
     */
    public function __invoke(AlertEscalationsRequest $request, #[CurrentUser] User $user, Project $project, AlertRule $rule, UpdateAlertEscalations $update): JsonResponse
    {
        $update->handle($rule, $user, $request->steps());

        return response()->json(['redirect' => route('monitoring.rules.show', [$project, $rule->id], false), 'message' => __('Escalation steps saved.')]);
    }
}
