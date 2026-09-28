<?php

declare(strict_types=1);

namespace App\Actions\Recipes;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteRecipe
{
    /**
     * Delete a recipe with its history. Servers keep the copy they were created with, and installed copies in other
     * accounts stay (they just lose the link to it).
     *
     * @param  User  $actor
     * @param  Recipe  $recipe
     * @return void
     */
    public function handle(User $actor, Recipe $recipe): void
    {
        Gate::forUser($actor)->authorize('delete', $recipe);
        $recipe->delete();
    }
}
