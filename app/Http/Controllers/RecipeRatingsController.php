<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecipeRatingsController extends Controller
{
    public function store(Request $request, Recipe $recipe, ActivityRecorder $activity): RedirectResponse
    {
        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);
        abort_if((int) $recipe->user_id === (int) $request->user()->id, 403);
        abort_unless($request->user()->recipes()->where('source_recipe_id', $recipe->id)->exists(), 403);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
        ]);

        $rating = $request->user()->recipeRatings()->updateOrCreate(
            ['recipe_id' => $recipe->id],
            ['rating' => $data['rating']],
        );
        $activity->record(
            $recipe,
            $request->user()->id,
            'recipe',
            $rating->wasRecentlyCreated
                ? "Gallery recipe \"{$recipe->name}\" was rated {$rating->rating}/5."
                : "Gallery recipe \"{$recipe->name}\" rating was updated to {$rating->rating}/5.",
        );

        return back()->with('status', __('Your gallery rating was saved.'));
    }

    public function destroy(Request $request, Recipe $recipe, ActivityRecorder $activity): RedirectResponse
    {
        $rating = $request->user()->recipeRatings()
            ->where('recipe_id', $recipe->id)
            ->firstOrFail();
        $rating->delete();
        $activity->record(
            $recipe,
            $request->user()->id,
            'recipe',
            "Gallery recipe \"{$recipe->name}\" rating was removed.",
        );

        return back()->with('status', __('Your gallery rating was removed.'));
    }
}
