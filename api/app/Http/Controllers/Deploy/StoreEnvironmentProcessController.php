<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveEnvironmentProcess;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreEnvironmentProcessController
{
    /**
     * Add or changes a worker or scheduler process on an environment.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SaveEnvironmentProcess  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveEnvironmentProcess $save): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/\A[a-z0-9][a-z0-9-]*\z/'],
            'type' => ['required', 'in:worker,scheduler'],
            'command' => ['required', 'string', 'max:2000', 'not_regex:/[\x00-\x1F]/'],
            'replicas' => ['required', 'integer', 'between:1,20'],
            'restart_policy' => ['required', 'in:always,on-failure'],
            'restart_delay_seconds' => ['required', 'integer', 'between:0,300'],
        ]);
        $save->handle($user, $environment, [
            'name' => (string) $data['name'], 'type' => (string) $data['type'], 'command' => (string) $data['command'], 'replicas' => (int) $data['replicas'],
            'restart_policy' => (string) $data['restart_policy'], 'restart_delay_seconds' => (int) $data['restart_delay_seconds'], 'is_enabled' => ! $request->has('disabled'),
        ]);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'processes'])->with('status', __('Process saved. It starts with the next deploy.'));
    }
}
