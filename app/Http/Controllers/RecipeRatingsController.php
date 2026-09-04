<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecipeRatingsController extends Controller
{
    public function store(Request $request, Recipe $recipe): RedirectResponse
    {
        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);
        abort_if((int) $recipe->user_id === (int) $request->user()->id, 403);
        abort_unless($request->user()->recipes()->where('source_recipe_id', $recipe->id)->exists(), 403);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
        ]);

        $request->user()->recipeRatings()->updateOrCreate(
            ['recipe_id' => $recipe->id],
            ['rating' => $data['rating']],
        );

        return back()->with('status', __('Your gallery rating was saved.'));
    }

    public function destroy(Request $request, Recipe $recipe): RedirectResponse
    {
        $rating = $request->user()->recipeRatings()
            ->where('recipe_id', $recipe->id)
            ->firstOrFail();
        $rating->delete();

        return back()->with('status', __('Your gallery rating was removed.'));
    }
}
