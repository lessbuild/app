<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Notifications')"
        :description="__('Review account security, deployment, and infrastructure alerts.')"
    >
        @if ($hasUnreadNotifications || $hasReadNotifications)
            <x-slot:buttons>
                @if ($hasUnreadNotifications)
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button type="submit" class="button primary">{{ __('Mark all as read') }}</button>
                    </form>
                @endif
                @if ($hasReadNotifications)
                    <form method="POST" action="{{ route('notifications.clear-read') }}">
                        @csrf
                        <button
                            type="submit"
                            class="button primary"
                            onclick="return confirm({{ Illuminate\Support\Js::from(__('Delete all read notifications? Unread notifications will be kept.')) }})"
                        >
                            {{ __('Clear read') }}
                        </button>
                    </form>
                @endif
            </x-slot:buttons>
        @endif
    </x-layouts.partials.heading>

    <form method="GET" action="{{ route('notifications.index') }}" class="mb-6 mt-8 rounded-lg border border-primary bg-primary p-4">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Notification title or message') }}"
                    class="input secondary mt-1 w-full rounded"
                >
            </div>
            <div>
                <label for="category" class="block text-xs font-semibold uppercase text-secondary">{{ __('Category') }}</label>
                <select id="category" name="category" class="input secondary mt-1 w-full rounded">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ str($category)->title() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="state" class="block text-xs font-semibold uppercase text-secondary">{{ __('State') }}</label>
                <select id="state" name="state" class="input secondary mt-1 w-full rounded">
                    <option value="">{{ __('Read and unread') }}</option>
                    <option value="unread" @selected($filters['state'] === 'unread')>{{ __('Unread') }}</option>
                    <option value="read" @selected($filters['state'] === 'read')>{{ __('Read') }}</option>
                </select>
            </div>
            <div>
                <label for="date_from" class="block text-xs font-semibold uppercase text-secondary">{{ __('Created from') }}</label>
                <input
                    id="date_from"
                    name="date_from"
                    type="date"
                    value="{{ $filters['date_from'] }}"
                    class="input secondary mt-1 w-full rounded"
                >
            </div>
            <div>
                <label for="date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('Created through') }}</label>
                <input
                    id="date_to"
                    name="date_to"
                    type="date"
                    value="{{ $filters['date_to'] }}"
                    class="input secondary mt-1 w-full rounded"
                >
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            <a href="{{ route('notifications.export', array_filter($filters, fn ($value) => $value !== null)) }}" class="button primary">
                {{ __('Export CSV') }}
            </a>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('notifications.index') }}" class="button primary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <div class="space-y-3">
        @forelse ($notifications as $notification)
            @php($destination = \App\Notifications\NotificationInbox::destination($notification->data))
            @php($notificationStatus = $notification->data['status'] ?? \App\Notifications\NotificationInbox::STATUS_FAILED)
            <article @class([
                'rounded-lg border p-5',
                'border-red-300 bg-red-50' => $notification->read_at === null && $notificationStatus === \App\Notifications\NotificationInbox::STATUS_FAILED,
                'border-green-300 bg-green-50' => $notification->read_at === null && $notificationStatus === \App\Notifications\NotificationInbox::STATUS_HEALTHY,
                'border-blue-300 bg-blue-50' => $notification->read_at === null && $notificationStatus === \App\Notifications\NotificationInbox::STATUS_INFO,
                'border-primary bg-primary' => $notification->read_at !== null,
            ])>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold text-primary">{{ $notification->data['title'] ?? __('Notification') }}</h2>
                            @if ($notification->read_at === null)
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-xs font-semibold uppercase text-white',
                                    'bg-red-600' => $notificationStatus === \App\Notifications\NotificationInbox::STATUS_FAILED,
                                    'bg-green-600' => $notificationStatus === \App\Notifications\NotificationInbox::STATUS_HEALTHY,
                                    'bg-blue-600' => $notificationStatus === \App\Notifications\NotificationInbox::STATUS_INFO,
                                ])>{{ __('Unread') }}</span>
                            @endif
                        </div>
                        <p class="mt-1 whitespace-pre-wrap break-words text-sm text-secondary">{{ $notification->data['message'] ?? __('Review this notification.') }}</p>
                        <p class="mt-2 text-xs text-secondary">{{ $notification->created_at->diffForHumans() }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($notification->read_at === null)
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                <button type="submit" class="button primary">
                                    {{ $destination ? __('View and mark read') : __('Mark as read') }}
                                </button>
                            </form>
                        @else
                            @if ($destination)
                                <a href="{{ $destination }}" class="button primary">{{ __('View') }}</a>
                            @endif
                            <form method="POST" action="{{ route('notifications.unread', $notification->id) }}">
                                @csrf
                                <button type="submit" class="button primary">{{ __('Mark unread') }}</button>
                            </form>
                        @endif
                        <form
                            method="POST"
                            action="{{ route('notifications.destroy', $notification->id) }}"
                            onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete this notification?')) }})"
                        >
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="button primary">{{ __('Delete') }}</button>
                        </form>
                    </div>
                </div>
            </article>
        @empty
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No notifications match these filters') : __('No notifications')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('New account security and operational alerts will appear here.')"
            />
        @endforelse
    </div>

    <div class="mt-6">
        {{ $notifications->links() }}
    </div>
</x-layouts.app>
