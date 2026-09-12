<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;

class RecipeGalleryQuery
{
    /**
     * Build the published gallery query for the requested personal scope and sort filters.
     *
     * @param  array{search: ?string, category: ?string, scope: string, sort: string}  $filters  Validated gallery filters.
     * @return Builder<Recipe> The filtered public recipe query.
     */
    public function for(array $filters, int $userId): Builder
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

    /**
     * Calculate gallery counts from the same published/scoped query used by the listing.
     *
     * @param  array{search: ?string, category: ?string, scope: string, sort: string}  $filters  Validated gallery filters.
     * @return array{published: int, installs: int, authors: int, ratings: int}
     */
    public function metrics(array $filters, int $userId): array
    {
        $query = $this->for($filters, $userId);

        return [
            'published' => (clone $query)->count(),
            'installs' => (int) (clone $query)->sum('install_count'),
            'authors' => (clone $query)->distinct()->count('user_id'),
            'ratings' => RecipeRating::query()
                ->whereIn('recipe_id', (clone $query)->select('recipes.id'))
                ->count(),
        ];
    }
}
