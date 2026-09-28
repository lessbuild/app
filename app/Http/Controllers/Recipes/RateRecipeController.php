<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\RateRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RateRecipeController
{
    /**
     * Rate a gallery recipe from 1 to 5 (PUT) or withdraw the rating (DELETE), and return to it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $recipe
     * @param  RateRecipe  $rate
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $recipe, RateRecipe $rate): RedirectResponse
    {
        $target = Recipe::query()->published()->findOrFail(ctype_digit($recipe) ? (int) $recipe : 0);
        if ($request->isMethod('PUT')) {
            $request->validate(['rating' => ['required', 'integer', 'between:1,5']]);
        }
        $rate->handle($user, $target, $request->isMethod('PUT') ? $request->integer('rating') : null);

        return to_route('recipes.gallery.show', $target->id)->with('status', $request->isMethod('PUT') ? __('Thanks for rating it.') : __('Rating withdrawn.'));
    }
}
