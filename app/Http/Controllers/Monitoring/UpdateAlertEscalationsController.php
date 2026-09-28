<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\UpdateAlertEscalations;
use App\Http\Requests\Monitoring\AlertEscalationsRequest;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

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
     * @return RedirectResponse
     */
    public function __invoke(AlertEscalationsRequest $request, #[CurrentUser] User $user, Project $project, AlertRule $rule, UpdateAlertEscalations $update): RedirectResponse
    {
        $update->handle($rule, $user, $request->steps());

        return to_route('monitoring.rules.show', [$project, $rule->id])->with('status', __('Escalation steps saved.'));
    }
}
