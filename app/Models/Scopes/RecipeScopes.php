<?php

namespace App\Models\Scopes;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-require-extends Recipe
 */
trait RecipeScopes
{
    /**
     * Limit the query to recipes with both a publication flag and timestamp.
     *
     * @param  Builder<Recipe>  $query  The query to constrain.
     * @return Builder<Recipe> The same query with the scope applied.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->whereNotNull('published_at');
    }

    /**
     * Limit the query to recipes attached to at least one server.
     *
     * @param  Builder<Recipe>  $query  The query to constrain.
     * @return Builder<Recipe> The same query with the scope applied.
     */
    public function scopeInUse(Builder $query): Builder
    {
        return $query->whereHas('servers');
    }

    /**
     * Limit the query to recipes without any attached servers.
     *
     * @param  Builder<Recipe>  $query  The query to constrain.
     * @return Builder<Recipe> The same query with the scope applied.
     */
    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereDoesntHave('servers');
    }
}
