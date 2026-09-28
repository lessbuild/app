<?php

declare(strict_types=1);

namespace App\Queries\Recipes;

use App\Enums\RecipeCategory;
use App\Models\Recipe;
use App\Models\RecipeFavorite;
use App\Models\RecipeRating;
use App\Models\RecipeReport;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** The gallery: published recipes from every account, and one recipe with what the viewer has done with it. */
final class RecipeGalleryQuery
{
    /**
     * Get published recipes, 24 a page, filtered by a search term (name or description), category and the viewer's
     * favourites, and sorted by installs (popular), average rating, or publication (newest). Each carries its rating
     * average and count, and whether the viewer's account has installed it and the viewer favourited it.
     *
     * @param  User  $viewer
     * @param  array{q?: string|null, category?: string|null, sort?: string|null, favorites?: bool}  $filters
     * @return LengthAwarePaginator<int, Recipe>
     */
    public function handle(User $viewer, array $filters): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $category = RecipeCategory::tryFrom((string) ($filters['category'] ?? ''));
        $query = Recipe::query()->published()->with('account')
            ->withAvg('ratings', 'rating')->withCount('ratings')
            ->withExists(['favorites as favorited' => fn (Builder $query) => $query->where('user_id', $viewer->id)])
            ->withExists(['copies as installed' => fn (Builder $query) => $query->where('account_id', $viewer->current_account_id)])
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('description', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($category !== null, fn (Builder $query) => $query->where('category', $category))
            ->when((bool) ($filters['favorites'] ?? false), fn (Builder $query) => $query->whereIn('id', RecipeFavorite::query()->where('user_id', $viewer->id)->select('recipe_id')));
        match ($filters['sort'] ?? 'popular') {
            'rating' => $query->orderByDesc('ratings_avg_rating')->orderByDesc('ratings_count'),
            'newest' => $query->orderByDesc('published_at'),
            default => $query->orderByDesc('install_count'),
        };

        return $query->orderBy('name')->paginate(24)->withQueryString();
    }

    /**
     * Get a published recipe with its rating average and count, and the viewer's rating, favourite, report, and their
     * account's installed copy.
     *
     * @param  User  $viewer
     * @param  string  $id
     * @return array{recipe: Recipe, rating: RecipeRating|null, favorited: bool, report: RecipeReport|null, copy: Recipe|null}
     */
    public function recipe(User $viewer, string $id): array
    {
        $recipe = Recipe::query()->published()->with('account')->withAvg('ratings', 'rating')->withCount('ratings')->findOrFail(ctype_digit($id) ? (int) $id : 0);

        return [
            'recipe' => $recipe,
            'rating' => RecipeRating::query()->where('recipe_id', $recipe->id)->where('user_id', $viewer->id)->first(),
            'favorited' => RecipeFavorite::query()->where('recipe_id', $recipe->id)->where('user_id', $viewer->id)->exists(),
            'report' => RecipeReport::query()->where('recipe_id', $recipe->id)->where('user_id', $viewer->id)->first(),
            'copy' => Recipe::query()->where('account_id', $viewer->current_account_id)->where('source_recipe_id', $recipe->id)->first(),
        ];
    }
}
