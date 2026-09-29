{{-- The bell's modal: the latest notifications, loaded each time it opens, with the full inbox a click away. --}}
<div class="grid gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted">{{ $unreadCount > 0 ? trans_choice(':count unread|:count unread', $unreadCount, ['count' => $unreadCount]) : __('You’re all caught up.') }}</p>
        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <input type="hidden" name="from_modal" value="1">
                <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Mark all as read') }}</x-signal.ui.button>
            </form>
        @endif
    </div>
    @if ($items->isEmpty())
        <p class="rounded-panel border border-dashed border-line p-4 text-sm text-muted">{{ __('We’ll let you know here when something changes for you, such as a new role or an API token about to expire.') }}</p>
    @else
        <div class="-mx-5 overflow-hidden border-y border-line sm:-mx-6">
            @include('notifications._list')
        </div>
    @endif
    <x-signal.ui.link :href="route('notifications.index')" class="text-sm font-bold">{{ __('See all notifications') }}</x-signal.ui.link>
</div>
