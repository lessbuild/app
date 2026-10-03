<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateEnvironmentRecipeSettings
{
    /**
     * Turn running the environment's recipes on new websites' servers on or off.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  bool  $runOnNewWebsites
     * @return void
     */
    public function handle(User $actor, Environment $environment, bool $runOnNewWebsites): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        $environment->forceFill(['recipes_run_on_new_websites' => $runOnNewWebsites])->save();
    }
}
