<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\UpdateAlertRouting;
use App\Http\Requests\Monitoring\AlertRoutingRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateAlertRoutingController
{
    public function __invoke(AlertRoutingRequest $request, #[CurrentUser] User $user, Project $project, string $rule, ProjectAlertRulesQuery $rules, UpdateAlertRouting $update): RedirectResponse
    {
        $target = $rules->find($project, $rule);
        $update->handle($target, $user, $request->routing());

        return to_route('monitoring.rules.show', [$project, $target->id])->with('status', __('Alert routing saved.'));
    }
}
