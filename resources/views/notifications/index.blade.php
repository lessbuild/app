<x-layouts.app>
    <x-layouts.partials.heading
        icon="bell"
        :title="__('Notifications')"
        :description="__('Review account security, deployment, infrastructure, and community feedback alerts.')"
    >
        @if ($hasUnreadNotifications || $hasReadNotifications)
            <x-slot:buttons>
                @if ($hasUnreadNotifications)
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">{{ __('Mark all as read') }}</x-ui.button>
                    </form>
                @endif
                @if ($hasReadNotifications)
                    <form method="POST" action="{{ route('notifications.clear-read') }}">
                        @csrf
                        <x-ui.button type="submit" variant="danger" onclick="return confirm({{ Illuminate\Support\Js::from(__('Delete all read notifications? Unread notifications will be kept.')) }})">{{ __('Clear read') }}</x-ui.button>
                    </form>
                @endif
            </x-slot:buttons>
        @endif
    </x-layouts.partials.heading>

    <x-ui.card class="mb-6 mt-8 p-4">
        <form method="GET" action="{{ route('notifications.index') }}">
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</span>
                    <input id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="{{ __('Notification title or message') }}" class="input secondary w-full rounded-md">
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Category') }}</span>
                    <select id="category" name="category" class="input secondary w-full rounded-md"><option value="">{{ __('All categories') }}</option>@foreach ($categories as $category)<option value="{{ $category }}" @selected($filters['category'] === $category)>{{ str($category)->title() }}</option>@endforeach</select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</span>
                    <select id="status" name="status" class="input secondary w-full rounded-md"><option value="">{{ __('All statuses') }}</option><option value="failed" @selected($filters['status'] === 'failed')>{{ __('Failed') }}</option><option value="healthy" @selected($filters['status'] === 'healthy')>{{ __('Recovered') }}</option><option value="info" @selected($filters['status'] === 'info')>{{ __('Information') }}</option></select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('State') }}</span>
                    <select id="state" name="state" class="input secondary w-full rounded-md"><option value="">{{ __('Read and unread') }}</option><option value="unread" @selected($filters['state'] === 'unread')>{{ __('Unread') }}</option><option value="read" @selected($filters['state'] === 'read')>{{ __('Read') }}</option></select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Created from') }}</span>
                    <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input secondary w-full rounded-md">
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Created through') }}</span>
                    <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input secondary w-full rounded-md">
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('notifications.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">{{ __('Export CSV') }}</x-ui.button>
                @if (array_filter($filters, fn ($value) => $value !== null))
                    <x-ui.button :href="route('notifications.index')" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.card>

    <section class="ui-card mb-6 p-4" aria-labelledby="saved-notification-filters">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="saved-notification-filters" class="font-bold text-primary">{{ __('Saved filters') }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ __('Reuse a notification view without rebuilding every filter.') }}</p>
            </div>
            <form method="POST" action="{{ route('notifications.saved-filters.store', array_filter($filters, fn ($value) => $value !== null)) }}" class="flex min-w-0 gap-2">
                @csrf
                <label class="min-w-0"><span class="sr-only">{{ __('Filter name') }}</span><input name="name" maxlength="40" required class="input secondary min-w-0 rounded-md" placeholder="{{ __('Filter name') }}"></label>
                <x-ui.button type="submit" variant="secondary">{{ __('Save current') }}</x-ui.button>
            </form>
        </div>
        @if ($savedFilters)
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($savedFilters as $saved)
                    <div class="flex items-center rounded-lg border border-primary bg-secondary">
                        <a href="{{ route('notifications.index', $saved['filters']) }}" class="px-3 py-2 text-sm font-bold text-primary">{{ $saved['name'] }}</a>
                        <form method="POST" action="{{ route('notifications.saved-filters.destroy', $saved['id']) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-r-lg px-3 py-2 text-secondary hover:bg-primary" aria-label="{{ __('Remove saved filter :name', ['name' => $saved['name']]) }}">×</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <dl class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <x-ui.stat :label="__('Matching alerts')" :value="$metrics['total']" :description="__('Alerts in this filtered view.')" />
        <x-ui.stat :label="__('Unread alerts')" :value="$metrics['unread']" :description="__('Matching alerts still awaiting review.')" />
        <x-ui.stat :label="__('Failures')" :value="$metrics['failed']" :description="__('Matching failed incidents.')" />
        <x-ui.stat :label="__('Recoveries')" :value="$metrics['healthy']" :description="__('Matching recovery notices.')" />
        <x-ui.stat :label="__('Information')" :value="$metrics['info']" :description="__('Matching informational notices.')" />
        <x-ui.stat :label="__('Latest matching alert')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching alert recorded.')" />
    </dl>

    <div
        class="space-y-3"
        x-data="{
            selected: [],
            pageIds: {{ Illuminate\Support\Js::from($notifications->pluck('id')->values()) }},
        }"
    >
        @if ($notifications->isNotEmpty())
            <form id="notification-bulk-form" method="POST" action="{{ route('notifications.bulk') }}" class="sticky top-3 z-10 mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-primary bg-primary p-3 shadow-xs">
                @csrf
                @method('PATCH')
                <x-ui.button type="button" variant="secondary" x-on:click="selected = selected.length === pageIds.length ? [] : [...pageIds]">
                    <span x-show="selected.length !== pageIds.length">{{ __('Select page') }}</span>
                    <span x-show="selected.length === pageIds.length" style="display: none">{{ __('Clear selection') }}</span>
                </x-ui.button>
                <span class="mr-auto text-xs font-semibold text-secondary" aria-live="polite"><span x-text="selected.length">0</span> {{ __('selected') }}</span>
                <x-ui.button type="submit" name="action" value="read" variant="secondary" x-bind:disabled="selected.length === 0">{{ __('Mark read') }}</x-ui.button>
                <x-ui.button type="submit" name="action" value="unread" variant="secondary" x-bind:disabled="selected.length === 0">{{ __('Mark unread') }}</x-ui.button>
                <x-ui.button type="submit" name="action" value="delete" variant="danger" x-bind:disabled="selected.length === 0" onclick="return confirm({{ Illuminate\Support\Js::from(__('Delete the selected notifications? This cannot be undone.')) }})">{{ __('Delete selected') }}</x-ui.button>
            </form>
            <x-forms.errors name="notifications" />
            <x-forms.errors name="notifications.*" />
            <x-forms.errors name="action" />
        @endif

        @forelse ($notifications as $notification)
            @php
                $destination = \App\Notifications\NotificationInbox::destination($notification->data);
                $notificationStatus = $notification->data['status'] ?? \App\Notifications\NotificationInbox::STATUS_FAILED;
                $notificationTone = match ($notificationStatus) {
                    \App\Notifications\NotificationInbox::STATUS_HEALTHY => 'success',
                    \App\Notifications\NotificationInbox::STATUS_INFO => 'accent',
                    default => 'danger',
                };
            @endphp
            <article @class([
                'ui-card p-5',
                'border-red-300 bg-red-50' => $notification->read_at === null && $notificationStatus === \App\Notifications\NotificationInbox::STATUS_FAILED,
                'border-green-300 bg-green-50' => $notification->read_at === null && $notificationStatus === \App\Notifications\NotificationInbox::STATUS_HEALTHY,
                'border-blue-300 bg-blue-50' => $notification->read_at === null && $notificationStatus === \App\Notifications\NotificationInbox::STATUS_INFO,
            ])>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="checkbox" name="notifications[]" value="{{ $notification->id }}" form="notification-bulk-form" x-model="selected" class="h-4 w-4 rounded border-primary text-blue-600 focus:ring-blue-500" aria-label="{{ __('Select notification: :title', ['title' => $notification->data['title'] ?? __('Notification')]) }}">
                            <h2 class="font-semibold text-primary">{{ $notification->data['title'] ?? __('Notification') }}</h2>
                            @if ($notification->read_at === null)
                                <x-ui.badge :tone="$notificationTone">{{ __('Unread') }}</x-ui.badge>
                            @else
                                <x-ui.badge>{{ __('Read') }}</x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-1 whitespace-pre-wrap break-words text-sm text-secondary">{{ $notification->data['message'] ?? __('Review this notification.') }}</p>
                        <p class="mt-2 text-xs text-secondary">{{ $notification->created_at->diffForHumans() }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($notification->read_at === null)
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary">{{ $destination ? __('View and mark read') : __('Mark as read') }}</x-ui.button>
                            </form>
                        @else
                            @if ($destination)
                                <x-ui.button :href="$destination" variant="secondary">{{ __('View') }}</x-ui.button>
                            @endif
                            <form method="POST" action="{{ route('notifications.unread', $notification->id) }}">
                                @csrf
                                <x-ui.button type="submit" variant="ghost">{{ __('Mark unread') }}</x-ui.button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('notifications.destroy', $notification->id) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete this notification?')) }})">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button>
                        </form>
                    </div>
                </div>
            </article>
        @empty
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No notifications match these filters') : __('No notifications')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('New account security and operational alerts will appear here.')"
                icon="bell"
            />
        @endforelse
    </div>

    <div class="mt-6">{{ $notifications->links() }}</div>
</x-layouts.app>
