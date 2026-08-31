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
        $filters = $this->indexFilters($request);
        $recipes = $request->user()->recipes()
            ->when($filters['search'], function ($query, string $value): void {
                $query->where(function ($query) use ($value): void {
                    $query
                        ->where('name', 'like', "%{$value}%")
                        ->orWhere('description', 'like', "%{$value}%");
                });
            })
            ->when($filters['usage'] === 'in_use', fn ($query) => $query
                ->whereHas('servers'))
            ->when($filters['usage'] === 'unused', fn ($query) => $query
                ->whereDoesntHave('servers'))
            ->withCount('servers')
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.recipes.index', [
            'recipes' => $recipes,
            'filters' => $filters,
            'usages' => ['in_use', 'unused'],
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

    /** @return array{search: ?string, usage: ?string} */
    private function indexFilters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $usage = $request->string('usage')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'usage' => in_array($usage, ['in_use', 'unused'], true) ? $usage : null,
        ];
    }
}
