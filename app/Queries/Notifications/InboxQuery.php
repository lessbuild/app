<?php

declare(strict_types=1);

namespace App\Queries\Notifications;

use App\Data\Notifications\InboxFilters;
use App\Data\Notifications\InboxItem;
use App\Models\User;
use App\Platform\Search\Like;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

final class InboxQuery
{
    /**
     * Get the person's notifications, newest first and cursor-paginated, narrowed by the filters.
     *
     * @param  User  $user
     * @param  InboxFilters  $filters
     * @param  int  $perPage
     * @return CursorPaginator<int, InboxItem> newest first
     */
    public function handle(User $user, InboxFilters $filters = new InboxFilters, int $perPage = 30): CursorPaginator
    {
        return $this->notifications($user, $filters)->cursorPaginate($perPage)->through(fn (DatabaseNotification $notification): InboxItem => $this->item($notification));
    }

    /**
     * Get every matching notification, newest first, a few hundred at a time, for exporting.
     *
     * @param  User  $user
     * @param  InboxFilters  $filters
     * @return LazyCollection<int, InboxItem>
     */
    public function export(User $user, InboxFilters $filters): LazyCollection
    {
        return $this->notifications($user, $filters)->lazy(500)->map(fn (DatabaseNotification $notification): InboxItem => $this->item($notification));
    }

    /**
     * List the kinds of notification the person has received, as class => label, for the filter.
     *
     * @param  User  $user
     * @return array<string, string>
     */
    public function types(User $user): array
    {
        return $user->notifications()->reorder()->distinct()->pluck('type')
            ->mapWithKeys(fn (mixed $type): array => [(string) $type => $this->label((string) $type)])->sort()->all();
    }

    /**
     * Count the person's unread notifications, for the badge in the shell.
     *
     * @param  User  $user
     * @return int
     */
    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * Name a notification class the way people read it, e.g. BuildAwaitingApproval → "Build awaiting approval".
     *
     * @param  string  $type
     * @return string
     */
    public function label(string $type): string
    {
        return Str::ucfirst(Str::lower(Str::headline(class_basename($type))));
    }

    /**
     * Query the person's notifications matching the filters, newest first.
     *
     * @param  User  $user
     * @param  InboxFilters  $filters
     * @return Builder<DatabaseNotification>
     */
    private function notifications(User $user, InboxFilters $filters): Builder
    {
        return $user->notifications()->getQuery()
            ->when($filters->unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->when($filters->type !== null, fn ($query) => $query->where('type', $filters->type))
            ->when($filters->search !== null, fn ($query) => $query->whereRaw("lower(data) like ? escape '\\'", [Like::contains((string) $filters->search)]))
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Turn a notification into what the inbox shows.
     *
     * @param  DatabaseNotification  $notification
     * @return InboxItem
     */
    private function item(DatabaseNotification $notification): InboxItem
    {
        return new InboxItem(
            id: (string) $notification->id,
            title: is_string($notification->data['title'] ?? null) ? $notification->data['title'] : __('Notification'),
            body: is_string($notification->data['body'] ?? null) ? $notification->data['body'] : '',
            read: $notification->read_at !== null,
            at: CarbonImmutable::instance($notification->created_at ?? now()),
        );
    }
}
