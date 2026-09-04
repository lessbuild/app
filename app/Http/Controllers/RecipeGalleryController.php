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

    public function show(Request $request, Recipe $recipe): View
    {
        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);
        $recipe->load('user:id,name');
        $installedRecipe = $request->user()->recipes()
            ->where('source_recipe_id', $recipe->id)
            ->latest('id')
            ->first();
        $installedRecipe?->setRelation('source', $recipe);

        return view('scenes.gallery.show', [
            'recipe' => $recipe,
            'installedRecipe' => $installedRecipe,
        ]);
    }

    public function install(Request $request, Recipe $recipe): RedirectResponse
    {
        $copy = DB::transaction(function () use ($request, $recipe): Recipe {
            $source = Recipe::query()
                ->published()
                ->lockForUpdate()
                ->findOrFail($recipe->id);

            $existing = $request->user()->recipes()
                ->where('source_recipe_id', $source->id)
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $copy = $request->user()->recipes()->create([
                'name' => $source->name,
                'description' => $source->description,
                'script' => $source->script,
                'source_recipe_id' => $source->id,
                'source_revision_at' => $source->gallery_revision_at,
                'is_published' => false,
            ]);

            $source->increment('install_count');

            return $copy;
        });

        return redirect()
            ->route('recipes.edit', $copy)
            ->with('status', $copy->wasRecentlyCreated
                ? __('Recipe added to your account. Review it before assigning it to a server.')
                : __('This gallery recipe is already in your account.'));
    }

    public function refresh(Recipe $recipe): RedirectResponse
    {
        $this->authorize('update', $recipe);

        $refreshed = DB::transaction(function () use ($recipe): bool {
            $copy = Recipe::query()->lockForUpdate()->findOrFail($recipe->id);
            if ($copy->is_published) {
                return false;
            }

            $source = Recipe::query()
                ->published()
                ->lockForUpdate()
                ->findOrFail($copy->source_recipe_id);

            $copy->update([
                'name' => $source->name,
                'description' => $source->description,
                'script' => $source->script,
                'source_revision_at' => $source->gallery_revision_at,
            ]);

            return true;
        });

        if (! $refreshed) {
            return redirect()
                ->route('recipes.edit', $recipe)
                ->with('status', __('Unpublish your copy before refreshing it from the gallery.'));
        }

        return redirect()
            ->route('recipes.edit', $recipe)
            ->with('status', __('Your private copy was refreshed from the reviewed gallery version.'));
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
