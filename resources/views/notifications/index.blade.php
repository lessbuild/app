<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Notifications')"
        :description="__('Review deployment and infrastructure alerts that need your attention.')"
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
        <div class="grid gap-4 md:grid-cols-3">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Title or failure message') }}"
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
            @php($destination = \App\Notifications\FailureNotification::destination($notification->data))
            @php($notificationStatus = $notification->data['status'] ?? 'failed')
            <article @class([
                'rounded-lg border p-5',
                'border-red-300 bg-red-50' => $notification->read_at === null && $notificationStatus !== 'healthy',
                'border-green-300 bg-green-50' => $notification->read_at === null && $notificationStatus === 'healthy',
                'border-primary bg-primary' => $notification->read_at !== null,
            ])>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold text-primary">{{ $notification->data['title'] ?? __('Infrastructure failure') }}</h2>
                            @if ($notification->read_at === null)
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-xs font-semibold uppercase text-white',
                                    'bg-red-600' => $notificationStatus !== 'healthy',
                                    'bg-green-600' => $notificationStatus === 'healthy',
                                ])>{{ __('Unread') }}</span>
                            @endif
                        </div>
                        <p class="mt-1 whitespace-pre-wrap break-words text-sm text-secondary">{{ $notification->data['message'] ?? __('The operation failed.') }}</p>
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
                    </div>
                </div>
            </article>
        @empty
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No notifications match these filters') : __('No notifications')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('New deployment and infrastructure alerts will appear here.')"
            />
        @endforelse
    </div>

    <div class="mt-6">
        {{ $notifications->links() }}
    </div>
</x-layouts.app>
