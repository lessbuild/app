<?php

namespace App\Http\Controllers;

use App\Actions\Notification\RemoveNotificationFilterAction;
use App\Actions\Notification\SaveNotificationFilterAction;
use App\Actions\Notification\UpdateNotificationStateAction;
use App\Enums\NotificationBulkOperation;
use App\Http\Requests\BulkNotificationRequest;
use App\Http\Requests\NotificationIndexRequest;
use App\Http\Requests\SaveNotificationFilterRequest;
use App\Notifications\NotificationInbox;
use App\Services\NotificationInboxExporter;
use App\Services\NotificationInboxQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NotificationsController extends Controller
{
    public function __construct(
        private readonly NotificationInboxQuery $inbox,
        private readonly NotificationInboxExporter $exporter,
        private readonly SaveNotificationFilterAction $saveNotificationFilter,
        private readonly RemoveNotificationFilterAction $removeNotificationFilter,
        private readonly UpdateNotificationStateAction $notificationState,
    ) {}

    /**
     * Render the user's filtered inbox, matching counts, read-state availability, and saved filter preferences.
     */
    public function index(NotificationIndexRequest $request): View
    {
        $filters = $request->filters();
        $user = $request->user();

        return view('notifications.index', [
            'notifications' => $this->inbox->for($user, $filters)
                ->latest('created_at')
                ->paginate(25)
                ->appends(array_filter($filters, fn ($value) => $value !== null)),
            'filters' => $filters,
            'metrics' => $this->inbox->metrics($user, $filters),
            'categories' => NotificationInbox::CATEGORIES,
            'hasUnreadNotifications' => $user->unreadNotifications()->exists(),
            'hasReadNotifications' => $user->readNotifications()->exists(),
            'savedFilters' => $user->preferences['notification_saved_filters'] ?? [],
        ]);
    }

    /**
     * Validate a filter name and save normalized inbox criteria, replacing matching names and retaining at most ten presets.
     */
    public function saveFilter(SaveNotificationFilterRequest $request): RedirectResponse
    {
        $this->saveNotificationFilter->handle($request->user(), $request->filterName(), $request->filters());

        return back()->with('success', __('Notification filter saved.'));
    }

    /**
     * Remove a saved-filter identifier from the request user's preferences and redirect with an acknowledgement.
     */
    public function destroyFilter(Request $request, string $filter): RedirectResponse
    {
        $this->removeNotificationFilter->handle($request->user(), $filter);

        return back()->with('success', __('Saved filter removed.'));
    }

    /**
     * Stream the user's filtered notification metadata as private CSV, excluding unsupported payload value types.
     */
    public function export(NotificationIndexRequest $request): StreamedResponse
    {
        return $this->exporter->stream($request->user(), $request->filters());
    }

    /**
     * Require notification ownership, mark it read, and redirect to its allowed destination or the inbox.
     */
    public function read(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->authorize('read', $notification);
        $this->notificationState->markRead($notification);

        return redirect(NotificationInbox::destination($notification->data) ?? route('notifications.index'));
    }

    /**
     * Mark all of the request user's unread notifications as read and redirect back.
     */
    public function readAll(Request $request): RedirectResponse
    {
        $this->notificationState->markAllRead($request->user());

        return back()->with('success', __('All notifications marked as read.'));
    }

    /**
     * Require notification ownership, clear its read timestamp, and redirect back.
     */
    public function unread(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->authorize('unread', $notification);
        $this->notificationState->markUnread($notification);

        return back()->with('success', __('Notification marked as unread.'));
    }

    /**
     * Delete only the request user's read notifications and redirect with the affected count.
     */
    public function clearRead(Request $request): RedirectResponse
    {
        $deleted = $this->notificationState->clearRead($request->user());

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
    public function bulk(BulkNotificationRequest $request): RedirectResponse
    {
        $operation = $request->operation();
        $affected = $this->notificationState->bulk($request->user(), $operation, $request->notificationIds());

        $message = match ($operation) {
            NotificationBulkOperation::Read => trans_choice(':count notification marked as read.|:count notifications marked as read.', $affected, ['count' => $affected]),
            NotificationBulkOperation::Unread => trans_choice(':count notification marked as unread.|:count notifications marked as unread.', $affected, ['count' => $affected]),
            NotificationBulkOperation::Delete => trans_choice(':count notification deleted.|:count notifications deleted.', $affected, ['count' => $affected]),
        };

        return back()->with('success', $message);
    }

    /**
     * Require ownership of the route-bound notification, delete it, and redirect back.
     */
    public function destroy(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->authorize('delete', $notification);
        $this->notificationState->delete($notification);

        return back()->with('success', __('Notification deleted.'));
    }
}
