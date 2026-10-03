<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\EnvironmentRecipe;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RefreshEnvironmentRecipe
{
    /**
     * Replace an environment recipe's snapshot with its library recipe as it is now.
     *
     * @param  User  $actor
     * @param  EnvironmentRecipe  $entry
     * @return void
     */
    public function handle(User $actor, EnvironmentRecipe $entry): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $entry->environment);
        $recipe = $entry->recipe ?? throw new StateConflict(__('The library recipe was deleted; this snapshot can’t be refreshed.'));
        $entry->snapshot($recipe);
        $entry->save();
    }
}
