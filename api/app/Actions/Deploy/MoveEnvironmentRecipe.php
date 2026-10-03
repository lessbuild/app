<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\EnvironmentRecipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class MoveEnvironmentRecipe
{
    /**
     * Move an environment recipe one place earlier or later in the run order, swapping with its neighbour.
     *
     * @param  User  $actor
     * @param  EnvironmentRecipe  $entry
     * @param  'up'|'down'  $direction
     * @return void
     */
    public function handle(User $actor, EnvironmentRecipe $entry, string $direction): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $entry->environment);
        DB::transaction(function () use ($entry, $direction): void {
            $neighbour = EnvironmentRecipe::query()->where('environment_id', $entry->environment_id)
                ->where('position', $direction === 'up' ? '<' : '>', $entry->position)
                ->orderBy('position', $direction === 'up' ? 'desc' : 'asc')->lockForUpdate()->first();
            if ($neighbour === null) {
                return;
            }
            [$mine, $theirs] = [$entry->position, $neighbour->position];
            $entry->forceFill(['position' => $theirs])->save();
            $neighbour->forceFill(['position' => $mine])->save();
        });
    }
}
