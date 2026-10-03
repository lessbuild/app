<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\PublishRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PublishRecipeController
{
    /**
     * Publish a recipe to the gallery or take it out, and return to it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Recipe  $recipe
     * @param  PublishRecipe  $publish
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Recipe $recipe, PublishRecipe $publish): RedirectResponse
    {
        $request->validate(['published' => ['required', 'boolean']]);
        $publish->handle($user, $recipe, $request->boolean('published'));

        return to_route('account.recipes.show', $recipe->id)->with('status', $request->boolean('published') ? __('Published. Every account can find it in the gallery.') : __('Taken out of the gallery. Installed copies stay.'));
    }
}
