<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveScalingSchedule;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreScalingScheduleController
{
    /**
     * Add a scaling schedule to an environment and return to its Automation tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SaveScalingSchedule  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveScalingSchedule $save): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'replicas' => ['required', 'integer', 'between:1,20'],
            'cron_expression' => ['required', 'string', 'max:100'], 'timezone' => ['required', 'string', 'max:64'],
        ]);
        $save->handle($user, $environment, [
            'name' => (string) $data['name'], 'replicas' => (int) $data['replicas'], 'cron_expression' => trim((string) $data['cron_expression']),
            'timezone' => (string) $data['timezone'], 'is_enabled' => true,
        ]);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'automation'])->with('status', __('Scaling schedule added.'));
    }
}
