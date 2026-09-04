<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RecipeGalleryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->galleryQuery($filters);

        return view('scenes.gallery.index', [
            'recipes' => (clone $query)
                ->with('user:id,name')
                ->when(
                    $filters['sort'] === 'popular',
                    fn (Builder $query) => $query->orderByDesc('install_count')->latest('published_at'),
                    fn (Builder $query) => $query->latest('published_at'),
                )
                ->paginate()
                ->withQueryString(),
            'filters' => $filters,
            'categories' => Recipe::CATEGORIES,
            'metrics' => [
                'published' => (clone $query)->count(),
                'installs' => (int) (clone $query)->sum('install_count'),
                'authors' => (clone $query)->distinct()->count('user_id'),
            ],
        ]);
    }

    public function show(Recipe $recipe): View
    {
        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);
        $recipe->load('user:id,name');

        return view('scenes.gallery.show', ['recipe' => $recipe]);
    }

    public function install(Request $request, Recipe $recipe): RedirectResponse
    {
        $copy = DB::transaction(function () use ($request, $recipe): Recipe {
            $source = Recipe::query()
                ->published()
                ->lockForUpdate()
                ->findOrFail($recipe->id);

            $copy = $request->user()->recipes()->create([
                'name' => $source->name,
                'description' => $source->description,
                'script' => $source->script,
                'source_recipe_id' => $source->id,
                'is_published' => false,
            ]);

            $source->increment('install_count');

            return $copy;
        });

        return redirect()
            ->route('recipes.edit', $copy)
            ->with('status', __('Recipe added to your account. Review it before assigning it to a server.'));
    }

    /** @return array{search: ?string, category: ?string, sort: string} */
    private function filters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $category = $request->string('category')->toString();
        $sort = $request->string('sort')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'category' => in_array($category, Recipe::CATEGORIES, true) ? $category : null,
            'sort' => in_array($sort, ['recent', 'popular'], true) ? $sort : 'recent',
        ];
    }

    /** @param array{search: ?string, category: ?string, sort: string} $filters */
    private function galleryQuery(array $filters): Builder
    {
        return Recipe::query()
            ->published()
            ->when($filters['search'], function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($filters['category'], fn (Builder $query, string $category) => $query->where('category', $category));
    }
}
