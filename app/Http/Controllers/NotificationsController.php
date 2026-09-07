<?php

namespace App\Http\Controllers;

use App\Notifications\NotificationInbox;
use App\Support\CsvCell;
use App\Support\DateRange;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NotificationsController extends Controller
{
    /**
     * Render the user's filtered inbox, matching counts, read-state availability, and saved filter preferences.
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $user = $request->user();

        return view('notifications.index', [
            'notifications' => $this->filteredNotifications($request, $filters)
                ->latest('created_at')
                ->paginate(25)
                ->appends(array_filter($filters, fn ($value) => $value !== null)),
            'filters' => $filters,
            'metrics' => $this->metrics($request, $filters),
            'categories' => NotificationInbox::CATEGORIES,
            'hasUnreadNotifications' => $user->unreadNotifications()->exists(),
            'hasReadNotifications' => $user->readNotifications()->exists(),
            'savedFilters' => $user->preferences['notification_saved_filters'] ?? [],
        ]);
    }

    /**
     * Validate a filter name and save normalized inbox criteria, replacing matching names and retaining at most ten presets.
     */
    public function saveFilter(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:40']]);
        $filters = $this->filters($request);
        $preferences = $request->user()->preferences ?? [];
        $saved = collect($preferences['notification_saved_filters'] ?? [])
            ->reject(fn (array $filter): bool => mb_strtolower($filter['name']) === mb_strtolower($data['name']))
            ->take(9)
            ->values();
        $saved->prepend(['id' => (string) str()->uuid(), 'name' => $data['name'], 'filters' => array_filter($filters, fn ($value) => $value !== null)]);
        $preferences['notification_saved_filters'] = $saved->all();
        $request->user()->update(['preferences' => $preferences]);

        return back()->with('success', __('Notification filter saved.'));
    }

    /**
     * Remove a saved-filter identifier from the request user's preferences and redirect with an acknowledgement.
     */
    public function destroyFilter(Request $request, string $filter): RedirectResponse
    {
        $preferences = $request->user()->preferences ?? [];
        $preferences['notification_saved_filters'] = collect($preferences['notification_saved_filters'] ?? [])
            ->reject(fn (array $saved): bool => hash_equals((string) $saved['id'], $filter))
            ->values()
            ->all();
        $request->user()->update(['preferences' => $preferences]);

        return back()->with('success', __('Saved filter removed.'));
    }

    /**
     * @param  array{search: ?string, category: ?string, status: ?string, state: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array{total: int, unread: int, failed: int, healthy: int, info: int, latest_at: CarbonInterface|null}
     */
    private function metrics(Request $request, array $filters): array
    {
        $latest = $this->filteredNotifications($request, $filters)
            ->select(['id', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->first();

        return [
            'total' => $this->filteredNotifications($request, $filters)->count(),
            'unread' => $this->filteredNotifications($request, $filters)->whereNull('read_at')->count(),
            'failed' => $this->filteredNotifications($request, $filters)
                ->where('data->status', NotificationInbox::STATUS_FAILED)
                ->count(),
            'healthy' => $this->filteredNotifications($request, $filters)
                ->where('data->status', NotificationInbox::STATUS_HEALTHY)
                ->count(),
            'info' => $this->filteredNotifications($request, $filters)
                ->where('data->status', NotificationInbox::STATUS_INFO)
                ->count(),
            'latest_at' => $latest?->created_at,
        ];
    }

    /**
     * Stream the user's filtered notification metadata as private CSV, excluding unsupported payload value types.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'lessbuild-notifications-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Notification ID',
                'Category',
                'Title',
                'Message',
                'Status',
                'State',
                'Resource ID',
                'Created at',
                'Read at',
            ], ',', '"', '');

            $this->filteredNotifications($request, $filters)
                ->latest('created_at')
                ->lazy(250)
                ->each(function (DatabaseNotification $notification) use ($output): void {
                    fputcsv($output, [
                        $notification->id,
                        $this->csvCell($this->dataValue($notification, 'category')),
                        $this->csvCell($this->dataValue($notification, 'title')),
                        $this->csvCell($this->dataValue($notification, 'message')),
                        $this->csvCell($this->dataValue($notification, 'status')),
                        $notification->read_at === null ? 'unread' : 'read',
                        $this->csvCell($this->dataValue($notification, 'resource_id')),
                        $notification->created_at?->toIso8601String(),
                        $notification->read_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Require notification ownership, mark it read, and redirect to its allowed destination or the inbox.
     */
    public function read(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->ensureOwnedNotification($request, $notification);
        $notification->markAsRead();

        return redirect(NotificationInbox::destination($notification->data) ?? route('notifications.index'));
    }

    /**
     * Mark all of the request user's unread notifications as read and redirect back.
     */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', __('All notifications marked as read.'));
    }

    /**
     * Require notification ownership, clear its read timestamp, and redirect back.
     */
    public function unread(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->ensureOwnedNotification($request, $notification);
        $notification->markAsUnread();

        return back()->with('success', __('Notification marked as unread.'));
    }

    /**
     * Delete only the request user's read notifications and redirect with the affected count.
     */
    public function clearRead(Request $request): RedirectResponse
    {
        $deleted = $request->user()->readNotifications()->delete();

        return back()->with('success', trans_choice(
            ':count read notification deleted.|:count read notifications deleted.',
            $deleted,
            ['count' => $deleted],
        ));
    }

    /**
     * Validate up to 25 distinct notification UUIDs and a read, unread, or delete action.
     *
     * @return RedirectResponse The count affected within the requesting user's own notifications.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:read,unread,delete'],
            'notifications' => ['required', 'array', 'min:1', 'max:25'],
            'notifications.*' => ['required', 'string', 'uuid', 'distinct'],
        ]);

        $notifications = $request->user()
            ->notifications()
            ->whereKey($validated['notifications']);

        $affected = match ($validated['action']) {
            'read' => $notifications->update(['read_at' => now()]),
            'unread' => $notifications->update(['read_at' => null]),
            'delete' => $notifications->delete(),
        };

        $message = match ($validated['action']) {
            'read' => trans_choice(':count notification marked as read.|:count notifications marked as read.', $affected, ['count' => $affected]),
            'unread' => trans_choice(':count notification marked as unread.|:count notifications marked as unread.', $affected, ['count' => $affected]),
            'delete' => trans_choice(':count notification deleted.|:count notifications deleted.', $affected, ['count' => $affected]),
        };

        return back()->with('success', $message);
    }

    /**
     * Require ownership of the route-bound notification, delete it, and redirect back.
     */
    public function destroy(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->ensureOwnedNotification($request, $notification);
        $notification->delete();

        return back()->with('success', __('Notification deleted.'));
    }

    /**
     * @param  Request  $request  The authenticated recipient context.
     * @param  DatabaseNotification  $notification  The route-bound notification.
     * @return void Reject records for any other recipient or morph type without revealing their existence.
     */
    private function ensureOwnedNotification(Request $request, DatabaseNotification $notification): void
    {
        $user = $request->user();
        abort_unless(
            (string) $notification->notifiable_id === (string) $user->getKey()
                && $notification->notifiable_type === $user->getMorphClass(),
            404,
        );
    }

    /** @return array{search: ?string, category: ?string, status: ?string, state: ?string, date_from: ?string, date_to: ?string} */
    private function filters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $category = $request->string('category')->toString();
        $status = $request->string('status')->toString();
        $state = $request->string('state')->toString();
        [$dateFrom, $dateTo] = DateRange::normalize(
            $request->string('date_from')->toString(),
            $request->string('date_to')->toString(),
        );

        return [
            'search' => $search !== '' ? $search : null,
            'category' => in_array($category, NotificationInbox::CATEGORIES, true) ? $category : null,
            'status' => in_array($status, NotificationInbox::STATUSES, true) ? $status : null,
            'state' => in_array($state, ['unread', 'read'], true) ? $state : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /** @param array{search: ?string, category: ?string, status: ?string, state: ?string, date_from: ?string, date_to: ?string} $filters */
    private function filteredNotifications(Request $request, array $filters): MorphMany
    {
        return $request->user()
            ->notifications()
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
     * Return an unchanged valid Y-m-d calendar date, or null for malformed or overflowing input.
     */
    private function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Read a notification payload key as string or integer, returning null for missing or unsupported values.
     */
    private function dataValue(DatabaseNotification $notification, string $key): string|int|null
    {
        $value = $notification->data[$key] ?? null;

        return is_string($value) || is_int($value) ? $value : null;
    }

    /**
     * Convert integer cells to text, preserve null, and escape values that could be interpreted as spreadsheet formulas.
     */
    private function csvCell(string|int|null $value): ?string
    {
        return CsvCell::escape($value);
    }
}
