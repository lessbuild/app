<x-layouts.app>
    <x-layouts.partials.heading
        eyebrow="{{ __('Health signals') }}"
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

    <x-ui.local-nav :label="__('Notification sections')">
        <a href="#notifications-insights" class="ui-local-nav__link">{{ __('Overview') }}</a>
        <a href="#notification-list" class="ui-local-nav__link">{{ __('Inbox') }}</a>
        <a href="#notification-filters" class="ui-local-nav__link">{{ __('Filters') }}</a>
        <a href="#notification-saved-filters" class="ui-local-nav__link">{{ __('Saved filters') }}</a>
    </x-ui.local-nav>

    @php
        $activeFilterCount = count(array_filter($filters, fn ($value) => $value !== null));
        $filtersAreActive = $activeFilterCount > 0;
        $savedFilterDialogHasErrors = $errors->has('name');
        $savedFilterDialogOpen = (request()->query('dialog') === 'save-filter' && ! session()->has('success'))
            || $savedFilterDialogHasErrors;
        $savedFilterDialogUrl = route('notifications.index', [
            'dialog' => 'save-filter',
            ...array_filter($filters, fn ($value) => $value !== null),
        ]);
        $savedFilterStoreUrl = route('notifications.saved-filters.store', array_filter($filters, fn ($value) => $value !== null));
    @endphp

    <x-ui.insights
        id="notifications-insights"
        class="mb-6"
        :summary="trans_choice(':count matching alert|:count matching alerts', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-ui.stat :label="__('Matching alerts')" :value="$metrics['total']" :description="__('Alerts in this filtered view.')" />
            <x-ui.stat :label="__('Unread alerts')" :value="$metrics['unread']" :description="__('Matching alerts still awaiting review.')" />
            <x-ui.stat :label="__('Failures')" :value="$metrics['failed']" :description="__('Matching failed incidents.')" />
            <x-ui.stat :label="__('Recoveries')" :value="$metrics['healthy']" :description="__('Matching recovery notices.')" />
            <x-ui.stat :label="__('Information')" :value="$metrics['info']" :description="__('Matching informational notices.')" />
            <x-ui.stat :label="__('Latest matching alert')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching alert recorded.')" />
        </dl>
    </x-ui.insights>

    <div
        id="notification-list"
        class="ui-inventory-list scroll-mt-24 space-y-3"
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
                'border-l-4 border-l-red-400' => $notification->read_at === null && $notificationStatus === \App\Notifications\NotificationInbox::STATUS_FAILED,
                'border-l-4 border-l-green-500' => $notification->read_at === null && $notificationStatus === \App\Notifications\NotificationInbox::STATUS_HEALTHY,
                'border-l-4 border-l-blue-500' => $notification->read_at === null && $notificationStatus === \App\Notifications\NotificationInbox::STATUS_INFO,
            ])>
                <div class="flex items-start gap-3">
                    <input type="checkbox" name="notifications[]" value="{{ $notification->id }}" form="notification-bulk-form" x-model="selected" class="mt-1 h-4 w-4 shrink-0 rounded border-primary text-blue-600 focus:ring-blue-500" aria-label="{{ __('Select notification: :title', ['title' => $notification->data['title'] ?? __('Notification')]) }}">
                    @if ($notification->read_at !== null)
                        <details id="notification-{{ $notification->id }}" class="group min-w-0 flex-1">
                            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-2 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                                <span class="min-w-0 flex-1 font-semibold text-primary">{{ $notification->data['title'] ?? __('Notification') }}</span>
                                <x-ui.badge>{{ __('Read') }}</x-ui.badge>
                                <span class="text-xs text-secondary">{{ $notification->created_at->diffForHumans() }}</span>
                                <span class="text-secondary transition-transform group-open:rotate-180" aria-hidden="true">⌄</span>
                            </summary>
                            <div class="mt-3">
                                <p class="whitespace-pre-wrap break-words text-sm text-secondary">{{ $notification->data['message'] ?? __('Review this notification.') }}</p>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    @if ($destination)
                                        <x-ui.button :href="$destination" variant="secondary">{{ __('View') }}</x-ui.button>
                                    @endif
                                    <form method="POST" action="{{ route('notifications.unread', $notification->id) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="ghost">{{ __('Mark unread') }}</x-ui.button>
                                    </form>
                                    <form method="POST" action="{{ route('notifications.destroy', $notification->id) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete this notification?')) }})">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button>
                                    </form>
                                </div>
                            </div>
                        </details>
                    @else
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-semibold text-primary">{{ $notification->data['title'] ?? __('Notification') }}</h2>
                                <x-ui.badge :tone="$notificationTone">{{ __('Unread') }}</x-ui.badge>
                            </div>
                            <p class="mt-1 whitespace-pre-wrap break-words text-sm text-secondary">{{ $notification->data['message'] ?? __('Review this notification.') }}</p>
                            <p class="mt-2 text-xs text-secondary">{{ $notification->created_at->diffForHumans() }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="secondary">{{ $destination ? __('View and mark read') : __('Mark as read') }}</x-ui.button>
                                </form>
                                <form method="POST" action="{{ route('notifications.destroy', $notification->id) }}" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete this notification?')) }})">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button>
                                </form>
                            </div>
                        </div>
                    @endif
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

    <section class="ui-card mb-6 mt-8 p-4" aria-labelledby="notification-tools">
        <h2 id="notification-tools" class="sr-only">{{ __('Notification tools') }}</h2>
        <details id="notification-filters" @if ($filtersAreActive) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-md font-bold text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                <span>{{ __('Filter notifications') }}</span>
                <span class="flex items-center gap-2">
                    @if ($filtersAreActive)
                        <x-ui.badge tone="accent">{{ trans_choice(':count active|:count active', $activeFilterCount, ['count' => $activeFilterCount]) }}</x-ui.badge>
                    @endif
                    <span class="text-secondary" aria-hidden="true">⌄</span>
                </span>
            </summary>
            <form method="GET" action="{{ route('notifications.index') }}" class="mt-4">
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
                    @if ($filtersAreActive)
                        <x-ui.button :href="route('notifications.index')" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
                    @endif
                </div>
            </form>
        </details>
    </section>

    <section class="ui-card mb-6 p-4" aria-labelledby="saved-notification-filters">
        <details id="notification-saved-filters" @if ($errors->has('name')) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-md font-bold text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                <span>{{ __('Saved filters') }}</span>
                <span class="flex items-center gap-2">
                    @if ($savedFilters)
                        <x-ui.badge>{{ count($savedFilters) }}</x-ui.badge>
                    @endif
                    <span class="text-secondary" aria-hidden="true">⌄</span>
                </span>
            </summary>
            <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                <p class="text-sm text-secondary">{{ __('Reuse a notification view without rebuilding every filter.') }}</p>
                <x-ui.button
                    href="{{ $savedFilterDialogUrl }}"
                    data-modal-trigger="notification-save-filter-dialog"
                    aria-controls="notification-save-filter-dialog"
                    aria-expanded="{{ $savedFilterDialogOpen ? 'true' : 'false' }}"
                    variant="secondary"
                >{{ __('Save current') }}</x-ui.button>
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
        </details>
    </section>

    <x-dialogs.modal
        id="notification-save-filter-dialog"
        :title="__('Save notification filter')"
        :description="__('Give this filtered notification view a short name so you can return to it later.')"
        :open="$savedFilterDialogOpen"
    >
        <form method="POST" action="{{ $savedFilterStoreUrl }}" class="space-y-4">
            @csrf
            <label class="block">
                <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Filter name') }}</span>
                <input name="name" value="{{ old('name') }}" maxlength="40" required class="input secondary w-full rounded-lg" placeholder="{{ __('Website incidents') }}">
                <x-forms.errors name="name" />
            </label>
            <x-ui.button type="submit" variant="primary">{{ __('Save current filter') }}</x-ui.button>
        </form>
    </x-dialogs.modal>
</x-layouts.app>
