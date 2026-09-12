<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\User;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderInventoryQuery
{
    /**
     * Build the organization-scoped provider query shared by the inventory page and export.
     *
     * @param  array{search: ?string, type: ?string, usage: ?string, connection: ?string}  $filters
     * @return HasMany<Provider, Organization> The filtered provider relationship for the current workspace.
     */
    public function for(User $user, array $filters): HasMany
    {
        return $user->workspaceProviders()
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['type'], fn ($query, string $value) => $query
                ->where('provider', $value))
            ->when($filters['usage'] === 'in_use', fn ($query) => $query->inUse())
            ->when($filters['usage'] === 'unused', fn ($query) => $query->unused())
            ->when($filters['connection'], fn ($query, string $status) => $query->connectionState($status));
    }

    /**
     * Calculate inventory counters from the same filtered workspace query used for the listing.
     *
     * @param  array{search: ?string, type: ?string, usage: ?string, connection: ?string}  $filters
     * @return array{total: int, in_use: int, unused: int, healthy: int, failed: int, unchecked: int}
     */
    public function metrics(User $user, array $filters): array
    {
        return [
            'total' => $this->for($user, $filters)->count(),
            'in_use' => $this->for($user, $filters)->inUse()->count(),
            'unused' => $this->for($user, $filters)->unused()->count(),
            'healthy' => $this->for($user, $filters)
                ->connectionState(Provider::CONNECTION_HEALTHY)
                ->count(),
            'failed' => $this->for($user, $filters)
                ->connectionState(Provider::CONNECTION_FAILED)
                ->count(),
            'unchecked' => $this->for($user, $filters)
                ->connectionState(Provider::CONNECTION_UNCHECKED)
                ->count(),
        ];
    }
}
