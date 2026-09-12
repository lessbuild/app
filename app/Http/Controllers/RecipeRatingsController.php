<?php

namespace App\Http\Controllers;

use App\Actions\Recipe\RemoveRecipeRatingAction;
use App\Actions\Recipe\SaveRecipeRatingAction;
use App\Http\Requests\StoreRecipeRatingRequest;
use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecipeRatingsController extends Controller
{
    /**
     * Validate a 1–5 rating for a published recipe installed in the workspace, excluding the contributor's own recipe.
     *
     * @return RedirectResponse A saved-rating acknowledgement after activity is recorded.
     */
    public function store(StoreRecipeRatingRequest $request, Recipe $recipe, SaveRecipeRatingAction $save): RedirectResponse
    {
        $save->handle($recipe, $request->user(), $request->rating());

        return back()->with('status', __('Your gallery rating was saved.'));
    }

    /**
     * Delete the request user's rating for the bound recipe, record the change, and redirect back.
     */
    public function destroy(Request $request, Recipe $recipe, RemoveRecipeRatingAction $remove): RedirectResponse
    {
        $remove->handle($recipe, $request->user());

        return back()->with('status', __('Your gallery rating was removed.'));
    }
}
