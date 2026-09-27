<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\UpdateAlertEscalations;
use App\Http\Requests\Monitoring\AlertEscalationsRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateAlertEscalationsController
{
    public function __invoke(AlertEscalationsRequest $request, #[CurrentUser] User $user, Project $project, string $rule, ProjectAlertRulesQuery $rules, UpdateAlertEscalations $update): RedirectResponse
    {
        $target = $rules->find($project, $rule);
        $update->handle($target, $user, $request->steps());

        return to_route('monitoring.rules.show', [$project, $target->id])->with('status', __('Escalation steps saved.'));
    }
}
