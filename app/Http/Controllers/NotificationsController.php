<?php

namespace App\Http\Controllers;

use App\Notifications\FailureNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $user = $request->user();

        return view('notifications.index', [
            'notifications' => $user
                ->notifications()
                ->when($filters['state'] === 'unread', fn ($query) => $query->whereNull('read_at'))
                ->when($filters['state'] === 'read', fn ($query) => $query->whereNotNull('read_at'))
                ->when($filters['category'], fn ($query, string $category) => $query
                    ->where('data->category', $category))
                ->when($filters['search'], function ($query, string $search): void {
                    $query->where(function ($query) use ($search): void {
                        $query
                            ->where('data->title', 'like', "%{$search}%")
                            ->orWhere('data->message', 'like', "%{$search}%");
                    });
                })
                ->latest('created_at')
                ->paginate(25)
                ->appends(array_filter($filters, fn ($value) => $value !== null)),
            'filters' => $filters,
            'categories' => FailureNotification::CATEGORIES,
            'hasUnreadNotifications' => $user->unreadNotifications()->exists(),
            'hasReadNotifications' => $user->readNotifications()->exists(),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $notification->markAsRead();

        return redirect(FailureNotification::destination($notification->data) ?? route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', __('All notifications marked as read.'));
    }

    public function unread(Request $request, string $notification): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $notification->markAsUnread();

        return back()->with('success', __('Notification marked as unread.'));
    }

    public function clearRead(Request $request): RedirectResponse
    {
        $deleted = $request->user()->readNotifications()->delete();

        return back()->with('success', trans_choice(
            ':count read notification deleted.|:count read notifications deleted.',
            $deleted,
            ['count' => $deleted],
        ));
    }

    /** @return array{search: ?string, category: ?string, state: ?string} */
    private function filters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $category = $request->string('category')->toString();
        $state = $request->string('state')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'category' => in_array($category, FailureNotification::CATEGORIES, true) ? $category : null,
            'state' => in_array($state, ['unread', 'read'], true) ? $state : null,
        ];
    }
}
