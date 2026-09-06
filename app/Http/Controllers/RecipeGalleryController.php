<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Services\ActivityRecorder;
use App\Support\SqlLike;
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
        $query = $this->galleryQuery($filters, $request->user()->id);
        $recipes = (clone $query)
            ->select([
                'recipes.id',
                'recipes.user_id',
                'recipes.name',
                'recipes.description',
                'recipes.category',
                'recipes.published_at',
                'recipes.gallery_revision_at',
                'recipes.install_count',
            ])
            ->with([
                'user:id,name',
                'installs' => fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->select(['id', 'user_id', 'source_recipe_id', 'source_revision_at', 'is_published'])
                    ->latest('id'),
                'favorites' => fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->select(['id', 'user_id', 'recipe_id']),
                'reports' => fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->select(['id', 'user_id', 'recipe_id', 'reason', 'resolved_at']),
            ])
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->when(
                $filters['sort'] === 'popular',
                fn (Builder $query) => $query->orderByDesc('install_count')->latest('published_at'),
                fn (Builder $query) => $filters['sort'] === 'top_rated'
                    ? $query->orderByDesc('ratings_avg_rating')->orderByDesc('ratings_count')->latest('published_at')
                    : $query->latest('published_at'),
            )
            ->paginate()
            ->withQueryString();

        return view('scenes.gallery.index', [
            'recipes' => $recipes,
            'filters' => $filters,
            'categories' => Recipe::CATEGORIES,
            'metrics' => [
                'published' => (clone $query)->count(),
                'installs' => (int) (clone $query)->sum('install_count'),
                'authors' => (clone $query)->distinct()->count('user_id'),
                'ratings' => RecipeRating::query()
                    ->whereIn('recipe_id', (clone $query)->select('recipes.id'))
                    ->count(),
            ],
        ]);
    }

    public function show(Request $request, Recipe $recipe): View
    {
        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);
        $recipe->load('user:id,name');
        $recipe->loadCount('ratings')->loadAvg('ratings', 'rating');
        $installedRecipe = $request->user()->workspaceRecipes()
            ->where('source_recipe_id', $recipe->id)
            ->latest('id')
            ->first();
        $installedRecipe?->setRelation('source', $recipe);

        return view('scenes.gallery.show', [
            'recipe' => $recipe,
            'installedRecipe' => $installedRecipe,
            'currentRating' => $request->user()->recipeRatings()
                ->where('recipe_id', $recipe->id)
                ->first(),
            'currentFavorite' => $request->user()->recipeFavorites()
                ->where('recipe_id', $recipe->id)
                ->first(),
            'currentReport' => $request->user()->recipeReports()
                ->where('recipe_id', $recipe->id)
                ->first(),
            'reportCounts' => (int) $recipe->user_id === (int) $request->user()->id
                ? $recipe->reports()
                    ->whereNull('resolved_at')
                    ->select('reason', DB::raw('COUNT(*) as total'))
                    ->groupBy('reason')
                    ->pluck('total', 'reason')
                : collect(),
            'recentReports' => (int) $recipe->user_id === (int) $request->user()->id
                ? $recipe->reports()
                    ->select(['id', 'recipe_id', 'reason', 'details', 'resolved_at', 'resolution_note', 'created_at'])
                    ->orderByRaw('resolved_at IS NULL DESC')
                    ->latest('id')
                    ->limit(20)
                    ->get()
                : collect(),
            'canRate' => $installedRecipe !== null
                && (int) $recipe->user_id !== (int) $request->user()->id,
        ]);
    }

    public function compare(Recipe $recipe, Recipe $copy): View
    {
        $this->authorize('update', $copy);
        abort_unless(
            $recipe->is_published
                && $recipe->published_at !== null
                && (int) $copy->source_recipe_id === (int) $recipe->id,
            404,
        );

        $recipe->load('user:id,name');
        $copy->setRelation('source', $recipe);

        return view('scenes.gallery.compare', [
            'recipe' => $recipe,
            'copy' => $copy,
            'comparison' => [
                'script_changed' => $copy->script !== $recipe->script,
                'name_changed' => $copy->name !== $recipe->name,
                'description_changed' => $copy->description !== $recipe->description,
                'current_lines' => $this->lineCount($copy->script),
                'gallery_lines' => $this->lineCount($recipe->script),
            ],
        ]);
    }

    public function install(Request $request, Recipe $recipe, ActivityRecorder $activity): RedirectResponse
    {
        $copy = DB::transaction(function () use ($request, $recipe): Recipe {
            $source = Recipe::query()
                ->published()
                ->lockForUpdate()
                ->findOrFail($recipe->id);

            $existing = $request->user()->workspaceRecipes()
                ->where('source_recipe_id', $source->id)
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $copy = $request->user()->workspaceRecipes()->create([
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

        if ($copy->wasRecentlyCreated) {
            $activity->record(
                $copy,
                $request->user()->id,
                'recipe',
                "Gallery recipe \"{$copy->name}\" was installed as a private copy.",
            );
        }

        return redirect()
            ->route('recipes.edit', $copy)
            ->with('status', $copy->wasRecentlyCreated
                ? __('Recipe added to your account. Review it before assigning it to a server.')
                : __('This gallery recipe is already in your account.'));
    }

    public function refresh(Recipe $recipe, ActivityRecorder $activity): RedirectResponse
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

        $recipe->refresh();
        $activity->record(
            $recipe,
            $recipe->user_id,
            'recipe',
            "Private gallery recipe \"{$recipe->name}\" was refreshed.",
        );

        return redirect()
            ->route('recipes.edit', $recipe)
            ->with('status', __('Your private copy was refreshed from the reviewed gallery version.'));
    }

    /** @return array{search: ?string, category: ?string, scope: string, sort: string} */
    private function filters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $category = $request->string('category')->toString();
        $scope = $request->string('scope')->toString();
        $sort = $request->string('sort')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'category' => in_array($category, Recipe::CATEGORIES, true) ? $category : null,
            'scope' => in_array($scope, ['all', 'favorites', 'reported', 'reports_open', 'reports_resolved', 'installed', 'updates', 'mine'], true) ? $scope : 'all',
            'sort' => in_array($sort, ['recent', 'popular', 'top_rated'], true) ? $sort : 'recent',
        ];
    }

    /** @param array{search: ?string, category: ?string, scope: string, sort: string} $filters */
    private function galleryQuery(array $filters, int $userId): Builder
    {
        return Recipe::query()
            ->published()
            ->when($filters['search'], function (Builder $query, string $search): void {
                $pattern = SqlLike::contains($search);
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['category'], fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['scope'] === 'mine', fn (Builder $query) => $query->where('user_id', $userId))
            ->when($filters['scope'] === 'favorites', function (Builder $query) use ($userId): void {
                $query->whereExists(function ($favorites) use ($userId): void {
                    $favorites
                        ->selectRaw('1')
                        ->from('recipe_favorites as gallery_favorites')
                        ->whereColumn('gallery_favorites.recipe_id', 'recipes.id')
                        ->where('gallery_favorites.user_id', $userId);
                });
            })
            ->when(in_array($filters['scope'], ['reported', 'reports_open', 'reports_resolved'], true), function (Builder $query) use ($filters, $userId): void {
                $query->whereExists(function ($reports) use ($filters, $userId): void {
                    $reports
                        ->selectRaw('1')
                        ->from('recipe_reports as gallery_reports')
                        ->whereColumn('gallery_reports.recipe_id', 'recipes.id')
                        ->where('gallery_reports.user_id', $userId)
                        ->when($filters['scope'] === 'reports_open', fn ($reports) => $reports->whereNull('gallery_reports.resolved_at'))
                        ->when($filters['scope'] === 'reports_resolved', fn ($reports) => $reports->whereNotNull('gallery_reports.resolved_at'));
                });
            })
            ->when(in_array($filters['scope'], ['installed', 'updates'], true), function (Builder $query) use ($filters, $userId): void {
                $query->whereExists(function ($installed) use ($filters, $userId): void {
                    $installed
                        ->selectRaw('1')
                        ->from('recipes as gallery_installs')
                        ->whereColumn('gallery_installs.source_recipe_id', 'recipes.id')
                        ->where('gallery_installs.user_id', $userId)
                        ->when($filters['scope'] === 'updates', function ($installed): void {
                            $installed->where(function ($revision): void {
                                $revision
                                    ->whereNull('gallery_installs.source_revision_at')
                                    ->orWhereColumn('gallery_installs.source_revision_at', '<', 'recipes.gallery_revision_at');
                            });
                        });
                });
            });
    }

    private function lineCount(string $script): int
    {
        return $script === '' ? 0 : substr_count($script, "\n") + 1;
    }
}
