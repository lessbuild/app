<?php

namespace App\Http\Controllers;

use App\Actions\Recipe\InstallGalleryRecipeAction;
use App\Actions\Recipe\RefreshGalleryRecipeAction;
use App\Http\Requests\RecipeGalleryIndexRequest;
use App\Models\Recipe;
use App\Services\RecipeGalleryQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RecipeGalleryController extends Controller
{
    public function __construct(private readonly RecipeGalleryQuery $gallery) {}

    /**
     * Render filtered published gallery recipes with ratings, personal saved/report state, installation context, and aggregate counts.
     */
    public function index(RecipeGalleryIndexRequest $request): View
    {
        $filters = $request->filters();
        $query = $this->gallery->for($filters, $request->user()->id);
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
            'metrics' => $this->gallery->metrics($filters, $request->user()->id),
        ]);
    }

    /**
     * Require a published recipe and render its installation, rating, and saved/report context for the request user.
     *
     * Contributor-only report summaries are withheld from other viewers.
     */
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

    /**
     * Require an editable copy of the published source recipe and render script, metadata, and line-count differences.
     */
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

    /**
     * Lock a published source and create one private workspace copy, reusing an existing installation when present.
     *
     * @return RedirectResponse The copy's editor with review guidance or an already-installed acknowledgement.
     */
    public function install(Request $request, Recipe $recipe, InstallGalleryRecipeAction $install): RedirectResponse
    {
        $copy = $install->handle($request->user(), $recipe);

        return redirect()
            ->route('recipes.edit', $copy)
            ->with('status', $copy->wasRecentlyCreated
                ? __('Recipe added to your account. Review it before assigning it to a server.')
                : __('This gallery recipe is already in your account.'));
    }

    /**
     * Authorize an unpublished copy and replace its contents from the currently published source under locks.
     *
     * @return RedirectResponse The copy editor; published copies are returned unchanged with an explanation.
     */
    public function refresh(Recipe $recipe, RefreshGalleryRecipeAction $refresh): RedirectResponse
    {
        $this->authorize('update', $recipe);

        $refreshed = $refresh->handle($recipe);

        if (! $refreshed) {
            return redirect()
                ->route('recipes.edit', $recipe)
                ->with('status', __('Unpublish your copy before refreshing it from the gallery.'));
        }

        return redirect()
            ->route('recipes.edit', $recipe)
            ->with('status', __('Your private copy was refreshed from the reviewed gallery version.'));
    }

    /**
     * Count newline-delimited script lines, returning zero for an empty script.
     */
    private function lineCount(string $script): int
    {
        return $script === '' ? 0 : substr_count($script, "\n") + 1;
    }
}
