<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\DeploymentSchedule;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentRecipe;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\ScalingSchedule;
use App\Models\ScheduledTask;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteEnvironmentSetting
{
    /**
     * Remove a variable, process or resource (the server changes with the next deploy), or a schedule or scheduled task
     * (with its runs), or a recipe (the library recipe stays).
     *
     * @param  User  $actor
     * @param  EnvironmentVariable|EnvironmentProcess|EnvironmentResource|DeploymentSchedule|ScalingSchedule|ScheduledTask  $setting
     * @return void
     */
    public function handle(User $actor, EnvironmentVariable|EnvironmentProcess|EnvironmentResource|DeploymentSchedule|ScalingSchedule|ScheduledTask|EnvironmentRecipe $setting): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $setting->environment);
        $setting->delete();
    }
}
