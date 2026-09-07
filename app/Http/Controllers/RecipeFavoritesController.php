<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecipeFavoritesController extends Controller
{
    /**
     * Require a published recipe and save it once to the request user's favorites, recording activity only for a new favorite.
     */
    public function store(Request $request, Recipe $recipe, ActivityRecorder $activity): RedirectResponse
    {
        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);

        $favorite = $request->user()->recipeFavorites()->firstOrCreate([
            'recipe_id' => $recipe->id,
        ]);

        if ($favorite->wasRecentlyCreated) {
            $activity->record(
                $recipe,
                $request->user()->id,
                'recipe',
                "Gallery recipe \"{$recipe->name}\" was saved.",
            );
        }

        return back()->with('status', $favorite->wasRecentlyCreated
            ? __('Recipe saved to your gallery favorites.')
            : __('This recipe is already in your gallery favorites.'));
    }

    /**
     * Delete the request user's existing favorite for the bound recipe, record the removal, and redirect back.
     */
    public function destroy(Request $request, Recipe $recipe, ActivityRecorder $activity): RedirectResponse
    {
        $request->user()->recipeFavorites()
            ->where('recipe_id', $recipe->id)
            ->firstOrFail()
            ->delete();
        $activity->record(
            $recipe,
            $request->user()->id,
            'recipe',
            "Gallery recipe \"{$recipe->name}\" was removed from saved recipes.",
        );

        return back()->with('status', __('Recipe removed from your gallery favorites.'));
    }
}
