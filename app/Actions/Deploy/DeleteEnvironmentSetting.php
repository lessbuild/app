<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\DeploymentSchedule;
use App\Models\EnvironmentFreeze;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentRecipe;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\ScalingSchedule;
use App\Models\ScheduledTask;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteEnvironmentSetting
{
    /**
     * Remove a variable, process or resource (the server changes with the next deploy), or a schedule or scheduled task
     * (with its runs), or a recipe (the library recipe stays).
     *
     * @param  User  $actor
     * @param  EnvironmentVariable|EnvironmentProcess|EnvironmentResource|DeploymentSchedule|ScalingSchedule|ScheduledTask|EnvironmentRecipe|EnvironmentFreeze  $setting
     * @return void
     */
    public function handle(User $actor, EnvironmentVariable|EnvironmentProcess|EnvironmentResource|DeploymentSchedule|ScalingSchedule|ScheduledTask|EnvironmentRecipe|EnvironmentFreeze $setting): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $setting->environment);
        if ($setting instanceof EnvironmentVariable && $setting->environment->require_variable_approval) {
            throw ValidationException::withMessages(['variables' => __('Variable changes in this environment need someone else’s approval.')]);
        }
        $setting->delete();
    }
}
