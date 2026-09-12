<?php

namespace App\Services;

use App\Models\User;
use App\Models\Website;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteInventoryQuery
{
    /**
     * Build the organization-scoped website query shared by the inventory page and export.
     *
     * @param  array{search: ?string, status: ?string, health: ?string, attention: ?string, provisioning: ?string}  $filters
     * @return HasMany<Website, Organization> The filtered website relationship for the current workspace.
     */
    public function for(User $user, array $filters): HasMany
    {
        return $user->workspaceWebsites()
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("url LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['status'], fn ($query, string $value) => $query
                ->where('provisioning_status', $value))
            ->when($filters['health'], function ($query, string $value): void {
                if ($value === 'disabled') {
                    $query->where('health_check_enabled', false);

                    return;
                }

                $query
                    ->where('health_check_enabled', true)
                    ->where('health_status', $value);
            })
            ->when($filters['attention'], fn ($query) => $query->needsAttention())
            ->when($filters['provisioning'], fn ($query) => $query
                ->whereIn('provisioning_status', Website::ACTIVE_PROVISIONING_STATUSES));
    }

    /**
     * Calculate inventory counters from the same filtered workspace query used by the listing.
     *
     * @param  array{search: ?string, status: ?string, health: ?string, attention: ?string, provisioning: ?string}  $filters
     * @return array{total: int, active: int, provisioning: int, failed: int, unhealthy: int, attention: int}
     */
    public function metrics(User $user, array $filters): array
    {
        return [
            'total' => $this->for($user, $filters)->count(),
            'active' => $this->for($user, $filters)
                ->where('provisioning_status', Website::STATUS_ACTIVE)
                ->count(),
            'provisioning' => $this->for($user, $filters)
                ->whereIn('provisioning_status', Website::ACTIVE_PROVISIONING_STATUSES)
                ->count(),
            'failed' => $this->for($user, $filters)
                ->where('provisioning_status', Website::STATUS_FAILED)
                ->count(),
            'unhealthy' => $this->for($user, $filters)
                ->where('health_check_enabled', true)
                ->where('health_status', Website::HEALTH_UNHEALTHY)
                ->count(),
            'attention' => $this->for($user, $filters)->needsAttention()->count(),
        ];
    }
}
