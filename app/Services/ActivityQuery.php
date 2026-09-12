<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityQuery
{
    /**
     * Build the request user's filtered activity query.
     *
     * @param  array{search: ?string, category: ?string, date_from: ?string, date_to: ?string}  $filters  Validated activity filters.
     * @return HasMany<Event, User> The user-scoped activity query.
     */
    public function for(User $user, array $filters): HasMany
    {
        return $user->events()
            ->when($filters['search'], fn ($query, string $value) => $query
                ->whereRaw("event LIKE ? ESCAPE '!'", [SqlLike::contains($value)]))
            ->when($filters['category'], fn ($query, string $value) => $query
                ->where('category', $value))
            ->when($filters['date_from'], fn ($query, string $value) => $query
                ->whereDate('created_at', '>=', $value))
            ->when($filters['date_to'], fn ($query, string $value) => $query
                ->whereDate('created_at', '<=', $value));
    }

    /**
     * Calculate activity metrics from the same filtered, user-scoped query used by the feed.
     *
     * @param  array{search: ?string, category: ?string, date_from: ?string, date_to: ?string}  $filters  Validated activity filters.
     * @return array{total: int, deployments: int, infrastructure: int, commands: int, recipes: int, account: int, latest_at: CarbonInterface|null}
     */
    public function metrics(User $user, array $filters): array
    {
        $latest = $this->for($user, $filters)
            ->select(['id', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->first();

        return [
            'total' => $this->for($user, $filters)->count(),
            'deployments' => $this->for($user, $filters)
                ->where('category', 'deployment')
                ->count(),
            'infrastructure' => $this->for($user, $filters)
                ->whereIn('category', ['website', 'server', 'provider'])
                ->count(),
            'commands' => $this->for($user, $filters)
                ->where('category', 'command')
                ->count(),
            'recipes' => $this->for($user, $filters)
                ->where('category', 'recipe')
                ->count(),
            'account' => $this->for($user, $filters)
                ->where('category', 'account')
                ->count(),
            'latest_at' => $latest?->created_at,
        ];
    }
}
