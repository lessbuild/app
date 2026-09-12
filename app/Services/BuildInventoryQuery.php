<?php

namespace App\Services;

use App\Models\Build;
use App\Models\User;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class BuildInventoryQuery
{
    /**
     * Build the organization-scoped deployment-history query shared by the listing, metrics, and export.
     *
     * @param  array{repository_id: ?int, website_id: ?int, server_id: ?int, provider_id: ?int, status: ?string, trigger: ?string, search: ?string, active: ?string, latest: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return Builder<Build> The filtered build query for the current workspace.
     */
    public function for(User $user, array $filters): Builder
    {
        return Build::query()
            ->whereHas('repository', fn ($query) => $query->where('organization_id', $user->current_organization_id))
            ->with('repository.website.server')
            ->when($filters['repository_id'], fn ($query, int $id) => $query
                ->where('builds.repository_id', $id))
            ->when($filters['website_id'], fn ($query, int $id) => $query
                ->whereHas('repository', fn ($query) => $query->where('website_id', $id)))
            ->when($filters['server_id'], fn ($query, int $id) => $query
                ->whereHas('repository.website', fn ($query) => $query->where('server_id', $id)))
            ->when($filters['provider_id'], fn ($query, int $id) => $query
                ->whereHas('repository', fn ($query) => $query->where('provider_id', $id)))
            ->when($filters['status'], fn ($query, string $value) => $query
                ->where('builds.status', $value))
            ->when($filters['trigger'], fn ($query, string $value) => $query
                ->where('builds.trigger_source', $value))
            ->when($filters['active'], fn ($query) => $query
                ->whereIn('builds.status', Build::ACTIVE_STATUSES))
            ->when($filters['latest'], fn ($query) => $query
                ->whereIn('builds.id', Build::query()
                    ->selectRaw('MAX(id)')
                    ->groupBy('repository_id')))
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("builds.revision LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("builds.commit_message LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("builds.operator_note LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereHas('repository', fn ($query) => $query
                            ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                });
            })
            ->when($filters['date_from'], fn ($query, string $date) => $query
                ->whereDate('builds.created_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query
                ->whereDate('builds.created_at', '<=', $date));
    }

    /**
     * Calculate deployment-history counters from the same filtered workspace query used by the listing.
     *
     * @param  array{repository_id: ?int, website_id: ?int, server_id: ?int, provider_id: ?int, status: ?string, trigger: ?string, search: ?string, active: ?string, latest: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array{total: int, active: int, succeeded: int, failed: int, success_rate: ?int, latest_at: CarbonInterface|null}
     */
    public function metrics(User $user, array $filters): array
    {
        $succeeded = $this->for($user, $filters)
            ->where('builds.status', Build::STATUS_SUCCEEDED)
            ->count();
        $failed = $this->for($user, $filters)
            ->where('builds.status', Build::STATUS_FAILED)
            ->count();
        $completed = $succeeded + $failed;
        $latest = $this->for($user, $filters)
            ->withoutEagerLoads()
            ->select(['builds.id', 'builds.created_at'])
            ->latest('builds.created_at')
            ->latest('builds.id')
            ->first();

        return [
            'total' => $this->for($user, $filters)->count(),
            'active' => $this->for($user, $filters)
                ->whereIn('builds.status', Build::ACTIVE_STATUSES)
                ->count(),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'success_rate' => $completed > 0 ? (int) round(($succeeded / $completed) * 100) : null,
            'latest_at' => $latest?->created_at,
        ];
    }
}
