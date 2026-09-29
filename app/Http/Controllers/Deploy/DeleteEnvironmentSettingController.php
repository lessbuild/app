<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\DeleteEnvironmentSetting;
use App\Actions\Deploy\RequestVariableChange;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
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
     * @param  RequestVariableChange  $requestChange
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Environment $environment, string $kind, string $setting, DeleteEnvironmentSetting $delete, RequestVariableChange $requestChange): RedirectResponse
    {
        $record = match ($kind) {
            'variables' => $environment->variables()->findOrFail((int) $setting),
            'processes' => $environment->processes()->findOrFail((int) $setting),
            'resources' => $environment->resources()->findOrFail((int) $setting),
            'deployment-schedules' => $environment->deploymentSchedules()->findOrFail((int) $setting),
            'scaling-schedules' => $environment->scalingSchedules()->findOrFail((int) $setting),
            'recipes' => $environment->recipes()->findOrFail((int) $setting),
            'freezes' => $environment->freezes()->findOrFail((int) $setting),
            default => $environment->scheduledTasks()->findOrFail((int) $setting),
        };
        if ($record instanceof EnvironmentVariable && $environment->require_variable_approval) {
            $requestChange->handle($user, $environment, 'delete', ['variable_id' => $record->id, 'key' => $record->key]);

            return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'])->with('status', __('Removing :key is waiting for someone else to approve it.', ['key' => $record->key]));
        }
        $delete->handle($user, $record);
        $automation = in_array($kind, ['deployment-schedules', 'scaling-schedules', 'tasks', 'recipes', 'freezes'], true);
        $tab = match ($kind) {
            'recipes' => 'recipes',
            'freezes' => 'controls',
            'deployment-schedules', 'scaling-schedules', 'tasks' => 'automation',
            default => $kind,
        };

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => $tab])
            ->with('status', $automation ? __('Removed.') : __('Removed. The server changes with the next deploy.'));
    }
}
