<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use App\Modules\Deployer\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

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
            ->when($filters['usage'] === 'in_use', fn ($query) => $this->usage($query, $user, true))
            ->when($filters['usage'] === 'unused', fn ($query) => $this->usage($query, $user, false))
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
            'in_use' => $this->usage($this->for($user, $filters), $user, true)->count(),
            'unused' => $this->usage($this->for($user, $filters), $user, false)->count(),
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

    /** Child labels and counts use the same project restrictions as their inventories. */
    public function associations(User $user): array
    {
        return [
            'servers' => fn ($query) => app(DeployerProjectAccess::class)->servers($query->where('servers.organization_id', $user->current_organization_id), $user),
            'repositories' => fn ($query) => app(DeployerProjectAccess::class)->repositories($query->where('repositories.organization_id', $user->current_organization_id), $user),
        ];
    }

    /** UI usage filters must not disclose associations the actor cannot inspect. */
    private function usage(Builder|Relation $query, User $user, bool $inUse): Builder|Relation
    {
        $associations = [
            ...$this->associations($user),
            'domains' => fn ($domain) => $domain->whereIn('website_id', $user->workspaceWebsites()->select('websites.id')),
        ];

        return $query->where(function ($usage) use ($associations, $inUse): void {
            foreach ($associations as $relation => $scope) {
                if ($inUse) {
                    $usage->orWhereHas($relation, $scope);
                } else {
                    $usage->whereDoesntHave($relation, $scope);
                }
            }
        });
    }
}
