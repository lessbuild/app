<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\DeleteEnvironmentSetting;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteEnvironmentSettingController
{
    /**
     * Remove one variable, process or resource from an environment (the server changes with the next deploy), or one
     * of its schedules or tasks.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  string  $kind
     * @param  string  $setting
     * @param  DeleteEnvironmentSetting  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Environment $environment, string $kind, string $setting, DeleteEnvironmentSetting $delete): RedirectResponse
    {
        $record = match ($kind) {
            'variables' => $environment->variables()->findOrFail((int) $setting),
            'processes' => $environment->processes()->findOrFail((int) $setting),
            'resources' => $environment->resources()->findOrFail((int) $setting),
            'deployment-schedules' => $environment->deploymentSchedules()->findOrFail((int) $setting),
            'scaling-schedules' => $environment->scalingSchedules()->findOrFail((int) $setting),
            'recipes' => $environment->recipes()->findOrFail((int) $setting),
            default => $environment->scheduledTasks()->findOrFail((int) $setting),
        };
        $delete->handle($user, $record);
        $automation = in_array($kind, ['deployment-schedules', 'scaling-schedules', 'tasks', 'recipes'], true);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => $kind === 'recipes' ? 'recipes' : ($automation ? 'automation' : $kind)])
            ->with('status', $automation ? __('Removed.') : __('Removed. The server changes with the next deploy.'));
    }
}
