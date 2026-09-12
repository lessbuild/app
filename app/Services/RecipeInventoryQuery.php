<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\User;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class RecipeInventoryQuery
{
    /**
     * Build the current-workspace recipe query shared by the inventory page and export.
     *
     * @param  array{search: ?string, usage: ?string}  $filters  Validated inventory filters.
     * @return HasMany<Recipe, Organization> The filtered recipe relationship for the current workspace.
     */
    public function for(User $user, array $filters): HasMany
    {
        return $user->workspaceRecipes()
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['usage'] === 'in_use', fn ($query) => $query->inUse())
            ->when($filters['usage'] === 'unused', fn ($query) => $query->unused());
    }

    /**
     * Calculate inventory metrics from the same filtered workspace query used by the listing.
     *
     * @param  array{search: ?string, usage: ?string}  $filters  Validated inventory filters.
     * @return array{total: int, in_use: int, unused: int, assignments: int, servers: int, latest_at: CarbonInterface|null}
     */
    public function metrics(User $user, array $filters): array
    {
        $latest = $this->for($user, $filters)
            ->select(['id', 'updated_at'])
            ->latest('updated_at')
            ->latest('id')
            ->first();
        $assignments = DB::table('recipe_server')->whereIn(
            'recipe_id',
            $this->for($user, $filters)->select('recipes.id'),
        );

        return [
            'total' => $this->for($user, $filters)->count(),
            'in_use' => $this->for($user, $filters)->inUse()->count(),
            'unused' => $this->for($user, $filters)->unused()->count(),
            'assignments' => (clone $assignments)->count(),
            'servers' => $assignments->distinct()->count('server_id'),
            'latest_at' => $latest?->updated_at,
        ];
    }
}
