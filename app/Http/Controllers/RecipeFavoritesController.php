<?php

namespace App\Http\Controllers;

use App\Actions\Recipe\RemoveRecipeFavoriteAction;
use App\Actions\Recipe\SaveRecipeFavoriteAction;
use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecipeFavoritesController extends Controller
{
    /**
     * Require a published recipe and save it once to the request user's favorites, recording activity only for a new favorite.
     */
    public function store(Request $request, Recipe $recipe, SaveRecipeFavoriteAction $save): RedirectResponse
    {
        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);

        $favorite = $save->handle($recipe, $request->user());

        return back()->with('status', $favorite->wasRecentlyCreated
            ? __('Recipe saved to your gallery favorites.')
            : __('This recipe is already in your gallery favorites.'));
    }

    /**
     * Delete the request user's existing favorite for the bound recipe, record the removal, and redirect back.
     */
    public function destroy(Request $request, Recipe $recipe, RemoveRecipeFavoriteAction $remove): RedirectResponse
    {
        $remove->handle($recipe, $request->user());

        return back()->with('status', __('Recipe removed from your gallery favorites.'));
    }
}
