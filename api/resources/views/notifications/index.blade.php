<x-signal.layouts.app :title="__('Notifications')">
    <x-signal.ui.page-header :title="__('Notifications')" :description="__('Changes that affect you across your accounts.')">
        @if ($unreadCount > 0)
            <x-slot:actions>
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Mark all as read') }}</x-signal.ui.button>
                </form>
            </x-slot:actions>
        @endif
    </x-signal.ui.page-header>

    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    <x-signal.ui.local-nav :label="__('Filter notifications')">
        <a href="{{ route('notifications.index', array_filter(['type' => $filters->type, 'q' => $filters->search])) }}" class="ui-local-nav__link" @unless ($unreadOnly) aria-current="page" @endunless>{{ __('All') }}</a>
        <a href="{{ route('notifications.index', array_filter(['filter' => 'unread', 'type' => $filters->type, 'q' => $filters->search])) }}" class="ui-local-nav__link" @if ($unreadOnly) aria-current="page" @endif>{{ __('Unread (:count)', ['count' => $unreadCount]) }}</a>
    </x-signal.ui.local-nav>

    <form method="GET" action="{{ route('notifications.index') }}" class="flex flex-wrap items-end gap-3">
        @if ($unreadOnly)<input type="hidden" name="filter" value="unread">@endif
        @if ($types !== [])
            <x-signal.ui.select-field name="type" :label="__('Kind')" :show-errors="false" field-class="min-w-56">
                <option value="">{{ __('Every kind') }}</option>
                @foreach ($types as $type => $label)
                    <option value="{{ $type }}" @selected($filters->type === $type)>{{ __($label) }}</option>
                @endforeach
            </x-signal.ui.select-field>
        @endif
        <x-signal.ui.input-field name="q" type="search" :label="__('Search')" :value="$filters->search" maxlength="100" :restore="false" :show-errors="false" />
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Show') }}</x-signal.ui.button>
        <x-signal.ui.button :href="route('notifications.export', request()->query())" variant="quiet">{{ __('Export CSV') }}</x-signal.ui.button>
    </form>
    @error('saved_view_name')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    <x-signal.ui.saved-views page="notifications" />

    @if ($items->isEmpty())
        <x-signal.ui.empty-state :title="$unreadOnly ? __('Nothing unread') : __('No notifications yet')" :description="__('We’ll let you know here when something changes for you, such as a new role or an API token about to expire.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            @include('notifications._list')
        </x-signal.ui.card>
        @if ($items->hasMorePages() || ! $items->onFirstPage())
            <nav class="flex justify-between gap-3" aria-label="{{ __('Notification pages') }}">
                <x-signal.ui.button :href="$items->previousPageUrl()" :disabled="$items->onFirstPage()" variant="secondary">{{ __('Newer') }}</x-signal.ui.button>
                <x-signal.ui.button :href="$items->nextPageUrl()" :disabled="! $items->hasMorePages()" variant="secondary">{{ __('Older') }}</x-signal.ui.button>
            </nav>
        @endif
    @endif
</x-signal.layouts.app>
