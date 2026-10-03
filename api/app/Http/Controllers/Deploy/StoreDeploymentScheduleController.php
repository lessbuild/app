<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveDeploymentSchedule;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreDeploymentScheduleController
{
    /**
     * Add a scheduled deploy to an environment and return to its Automation tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SaveDeploymentSchedule  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveDeploymentSchedule $save): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'cron_expression' => ['required', 'string', 'max:100'], 'timezone' => ['required', 'string', 'max:64']]);
        $save->handle($user, $environment, ['name' => (string) $data['name'], 'cron_expression' => trim((string) $data['cron_expression']), 'timezone' => (string) $data['timezone'], 'is_enabled' => true]);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'automation'])->with('status', __('Scheduled deploy added.'));
    }
}
