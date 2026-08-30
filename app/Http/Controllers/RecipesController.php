<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecipeRequest;
use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RecipesController extends Controller
{
    public function index(Request $request): View
    {
        return view('scenes.recipes.index', [
            'recipes' => $request->user()->recipes()->withCount('servers')->latest()->paginate(),
        ]);
    }

    public function create(): View
    {
        return view('scenes.recipes.create');
    }

    public function store(RecipeRequest $request): RedirectResponse
    {
        $request->user()->recipes()->create($request->validated());

        return redirect()->route('recipes.index')->with('status', __('Recipe created.'));
    }

    public function edit(Recipe $recipe): View
    {
        $this->authorize('update', $recipe);

        return view('scenes.recipes.edit', ['recipe' => $recipe]);
    }

    public function update(RecipeRequest $request, Recipe $recipe): RedirectResponse
    {
        $this->authorize('update', $recipe);
        $recipe->update($request->validated());

        return redirect()->route('recipes.index')->with('status', __('Recipe updated.'));
    }

    public function destroy(Recipe $recipe): RedirectResponse
    {
        $this->authorize('delete', $recipe);
        $recipe->delete();

        return redirect()->route('recipes.index')->with('status', __('Recipe deleted.'));
    }
}
