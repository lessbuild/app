<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerInventoryQuery
{
    /**
     * Build the organization-scoped server query shared by the listing, metrics, and export.
     *
     * @param  array{search: ?string, status: ?string, provisioning: ?string}  $filters
     * @return HasMany<Server, Organization> The filtered server relationship for the current workspace.
     */
    public function for(User $user, array $filters): HasMany
    {
        return $user->workspaceServers()
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("display_name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("identifier LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("public_ip LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("private_ip LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['status'], fn ($query, string $value) => $query
                ->where('provisioning_status', $value))
            ->when($filters['provisioning'], fn ($query) => $query
                ->whereIn('provisioning_status', Server::ACTIVE_PROVISIONING_STATUSES));
    }

    /**
     * Calculate server capacity counters from the same filtered workspace query used by the listing.
     *
     * @param  array{search: ?string, status: ?string, provisioning: ?string}  $filters
     * @return array{total: int, ready: int, provisioning: int, failed: int, websites: int, latest_at: CarbonInterface|null}
     */
    public function metrics(User $user, array $filters): array
    {
        $latest = $this->for($user, $filters)
            ->select(['id', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->first();
        $serverIds = $this->for($user, $filters)->select('servers.id');

        return [
            'total' => $this->for($user, $filters)->count(),
            'ready' => $this->for($user, $filters)
                ->where('provisioning_status', Server::STATUS_ACTIVE)
                ->count(),
            'provisioning' => $this->for($user, $filters)
                ->whereIn('provisioning_status', Server::ACTIVE_PROVISIONING_STATUSES)
                ->count(),
            'failed' => $this->for($user, $filters)
                ->where('provisioning_status', Server::STATUS_FAILED)
                ->count(),
            'websites' => $user->workspaceWebsites()->whereIn('server_id', $serverIds)->count(),
            'latest_at' => $latest?->created_at,
        ];
    }
}
