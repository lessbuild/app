<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteEnvironmentSetting
{
    /** Remove a variable, process or resource; the server changes with the next deploy. */
    public function handle(User $actor, EnvironmentVariable|EnvironmentProcess|EnvironmentResource $setting): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $setting->environment);
        $setting->delete();
    }
}
