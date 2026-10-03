<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\EnvironmentRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AddEnvironmentRecipe
{
    /**
     * Add a snapshot of one of the account's recipes to the end of the environment's list. Each recipe can be on an
     * environment once.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  int  $recipeId
     * @return EnvironmentRecipe
     */
    public function handle(User $actor, Environment $environment, int $recipeId): EnvironmentRecipe
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        $recipe = Recipe::query()->where('account_id', $environment->project->account_id)->find($recipeId)
            ?? throw ValidationException::withMessages(['recipe_id' => __('Choose one of this account’s recipes.')]);

        return DB::transaction(function () use ($actor, $environment, $recipe): EnvironmentRecipe {
            if ($environment->recipes()->where('recipe_id', $recipe->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['recipe_id' => __('That recipe is already on this environment.')]);
            }
            $entry = new EnvironmentRecipe;
            $entry->snapshot($recipe);
            $entry->forceFill(['environment_id' => $environment->id, 'position' => (int) $environment->recipes()->max('position') + 1, 'added_by' => $actor->id])->save();

            return $entry;
        });
    }
}
