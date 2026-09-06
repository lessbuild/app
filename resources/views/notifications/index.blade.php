<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Notifications')"
        :description="__('Review account security, deployment, infrastructure, and community feedback alerts.')"
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
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Notification title or message') }}"
                    class="input secondary mt-1 w-full rounded-sm"
                >
            </div>
            <div>
                <label for="category" class="block text-xs font-semibold uppercase text-secondary">{{ __('Category') }}</label>
                <select id="category" name="category" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ str($category)->title() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</label>
                <select id="status" name="status" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All statuses') }}</option>
                    <option value="failed" @selected($filters['status'] === 'failed')>{{ __('Failed') }}</option>
                    <option value="healthy" @selected($filters['status'] === 'healthy')>{{ __('Recovered') }}</option>
                    <option value="info" @selected($filters['status'] === 'info')>{{ __('Information') }}</option>
                </select>
            </div>
            <div>
                <label for="state" class="block text-xs font-semibold uppercase text-secondary">{{ __('State') }}</label>
                <select id="state" name="state" class="input secondary mt-1 w-full rounded-sm">
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
                    class="input secondary mt-1 w-full rounded-sm"
                >
            </div>
            <div>
                <label for="date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('Created through') }}</label>
                <input
                    id="date_to"
                    name="date_to"
                    type="date"
                    value="{{ $filters['date_to'] }}"
                    class="input secondary mt-1 w-full rounded-sm"
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

    <section class="mb-6 rounded-lg border border-primary bg-primary p-4" aria-labelledby="saved-notification-filters">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><h2 id="saved-notification-filters" class="font-bold text-primary">{{ __('Saved filters') }}</h2><p class="mt-1 text-sm text-secondary">{{ __('Reuse a notification view without rebuilding every filter.') }}</p></div>
            <form method="POST" action="{{ route('notifications.saved-filters.store', array_filter($filters, fn ($value) => $value !== null)) }}" class="flex gap-2">@csrf<input name="name" maxlength="40" required class="input secondary min-w-0 rounded-sm" placeholder="{{ __('Filter name') }}"><button type="submit" class="button primary">{{ __('Save current') }}</button></form>
        </div>
        @if($savedFilters)
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($savedFilters as $saved)
                    <div class="flex items-center rounded-lg border border-primary bg-secondary"><a href="{{ route('notifications.index', $saved['filters']) }}" class="px-3 py-2 text-sm font-bold text-primary">{{ $saved['name'] }}</a><form method="POST" action="{{ route('notifications.saved-filters.destroy', $saved['id']) }}">@csrf @method('DELETE')<button type="submit" class="px-2 py-2 text-secondary" aria-label="{{ __('Remove saved filter :name', ['name' => $saved['name']]) }}">×</button></form></div>
                @endforeach
            </div>
        @endif
    </section>

    <dl class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Matching alerts') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['total'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Alerts in this filtered view.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Unread alerts') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['unread'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching alerts still awaiting review.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Failures') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['failed'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching failed incidents.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Recoveries') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['healthy'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching recovery notices.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Information') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['info'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching informational notices.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Latest matching alert') }}</dt>
            <dd class="mt-1 text-lg font-bold text-primary">{{ $metrics['latest_at']?->diffForHumans() ?? __('Not available') }}</dd>
            <dd class="mt-1 text-xs text-secondary">
                {{ $metrics['latest_at']?->toDayDateTimeString() ?? __('No matching alert recorded.') }}
            </dd>
        </div>
    </dl>

    <div
        class="space-y-3"
        x-data="{
            selected: [],
            pageIds: {{ Illuminate\Support\Js::from($notifications->pluck('id')->values()) }},
        }"
    >
        @if ($notifications->isNotEmpty())
            <form id="notification-bulk-form" method="POST" action="{{ route('notifications.bulk') }}" class="sticky top-3 z-10 mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-primary bg-primary p-3 shadow-xs">
                @csrf
                @method('PATCH')
                <button type="button" class="button tertiary" x-on:click="selected = selected.length === pageIds.length ? [] : [...pageIds]">
                    <span x-show="selected.length !== pageIds.length">{{ __('Select page') }}</span>
                    <span x-show="selected.length === pageIds.length" style="display: none">{{ __('Clear selection') }}</span>
                </button>
                <span class="mr-auto text-xs font-semibold text-secondary" aria-live="polite"><span x-text="selected.length">0</span> {{ __('selected') }}</span>
                <button type="submit" name="action" value="read" class="button secondary disabled:cursor-not-allowed disabled:opacity-50" x-bind:disabled="selected.length === 0">{{ __('Mark read') }}</button>
                <button type="submit" name="action" value="unread" class="button secondary disabled:cursor-not-allowed disabled:opacity-50" x-bind:disabled="selected.length === 0">{{ __('Mark unread') }}</button>
                <button
                    type="submit"
                    name="action"
                    value="delete"
                    class="button secondary disabled:cursor-not-allowed disabled:opacity-50"
                    x-bind:disabled="selected.length === 0"
                    onclick="return confirm({{ Illuminate\Support\Js::from(__('Delete the selected notifications? This cannot be undone.')) }})"
                >{{ __('Delete selected') }}</button>
            </form>
            <x-forms.errors name="notifications" />
            <x-forms.errors name="notifications.*" />
            <x-forms.errors name="action" />
        @endif

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
                            <input
                                type="checkbox"
                                name="notifications[]"
                                value="{{ $notification->id }}"
                                form="notification-bulk-form"
                                x-model="selected"
                                class="h-4 w-4 rounded-sm border-primary text-blue-600 focus:ring-blue-500"
                                aria-label="{{ __('Select notification: :title', ['title' => $notification->data['title'] ?? __('Notification')]) }}"
                            >
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
