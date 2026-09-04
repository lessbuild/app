<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecipeReportsController extends Controller
{
    public function store(Request $request, Recipe $recipe, ActivityRecorder $activity): RedirectResponse
    {
        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);
        abort_if((int) $recipe->user_id === (int) $request->user()->id, 403);

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(RecipeReport::REASONS)],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['details'] = filled($data['details'] ?? null)
            ? str($data['details'])->trim()->toString()
            : null;

        $report = $request->user()->recipeReports()->updateOrCreate(
            ['recipe_id' => $recipe->id],
            $data,
        );
        $activity->record(
            $recipe,
            $request->user()->id,
            'recipe',
            $report->wasRecentlyCreated
                ? "Gallery recipe \"{$recipe->name}\" was reported as {$report->reason}."
                : "Gallery recipe \"{$recipe->name}\" report was updated to {$report->reason}.",
        );

        return back()->with('status', __('Your private gallery report was saved.'));
    }

    public function destroy(Request $request, Recipe $recipe, ActivityRecorder $activity): RedirectResponse
    {
        $request->user()->recipeReports()
            ->where('recipe_id', $recipe->id)
            ->firstOrFail()
            ->delete();
        $activity->record(
            $recipe,
            $request->user()->id,
            'recipe',
            "Gallery recipe \"{$recipe->name}\" report was withdrawn.",
        );

        return back()->with('status', __('Your gallery report was withdrawn.'));
    }
}
