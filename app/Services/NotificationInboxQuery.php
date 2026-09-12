<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\NotificationInbox;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class NotificationInboxQuery
{
    /**
     * Build the authenticated user's filtered notification query.
     *
     * @param  array{search: ?string, category: ?string, status: ?string, state: ?string, date_from: ?string, date_to: ?string}  $filters  Validated inbox filters.
     */
    public function for(User $user, array $filters): MorphMany
    {
        return $user->notifications()
            ->when($filters['state'] === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($filters['state'] === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->when($filters['category'], fn ($query, string $category) => $query
                ->where('data->category', $category))
            ->when($filters['status'], fn ($query, string $status) => $query
                ->where('data->status', $status))
            ->when($filters['search'], function ($query, string $search): void {
                $pattern = SqlLike::contains($search);
                $grammar = $query->getQuery()->getGrammar();
                $title = $grammar->wrap('data->title');
                $message = $grammar->wrap('data->message');

                $query->where(function ($query) use ($message, $pattern, $title): void {
                    $query
                        ->whereRaw("{$title} LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("{$message} LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['date_from'], fn ($query, string $date) => $query
                ->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query
                ->whereDate('created_at', '<=', $date));
    }

    /**
     * Calculate inbox metrics from the same recipient-scoped filtered query used by the listing.
     *
     * @param  array{search: ?string, category: ?string, status: ?string, state: ?string, date_from: ?string, date_to: ?string}  $filters  Validated inbox filters.
     * @return array{total: int, unread: int, failed: int, healthy: int, info: int, latest_at: CarbonInterface|null}
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
            'unread' => $this->for($user, $filters)->whereNull('read_at')->count(),
            'failed' => $this->for($user, $filters)
                ->where('data->status', NotificationInbox::STATUS_FAILED)
                ->count(),
            'healthy' => $this->for($user, $filters)
                ->where('data->status', NotificationInbox::STATUS_HEALTHY)
                ->count(),
            'info' => $this->for($user, $filters)
                ->where('data->status', NotificationInbox::STATUS_INFO)
                ->count(),
            'latest_at' => $latest?->created_at,
        ];
    }
}
