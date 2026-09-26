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
        <a href="{{ route('notifications.index') }}" class="ui-local-nav__link" @unless ($unreadOnly) aria-current="page" @endunless>{{ __('All') }}</a>
        <a href="{{ route('notifications.index', ['filter' => 'unread']) }}" class="ui-local-nav__link" @if ($unreadOnly) aria-current="page" @endif>{{ __('Unread (:count)', ['count' => $unreadCount]) }}</a>
    </x-signal.ui.local-nav>

    @if ($items->isEmpty())
        <x-signal.ui.empty-state :title="$unreadOnly ? __('Nothing unread') : __('No notifications yet')" :description="__('We’ll let you know here when something changes for you, such as a new role or an API token about to expire.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Notifications') }}">
                @foreach ($items as $item)
                    <li>
                        <a href="{{ route('notifications.open', $item->id) }}" @class(['flex items-start gap-3 px-5 py-4 hover:bg-surface-muted focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus', 'bg-primary-soft/40' => ! $item->read])>
                            <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-primary' => ! $item->read, 'bg-transparent' => $item->read]) aria-hidden="true"></span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block text-sm', 'font-extrabold text-ink' => ! $item->read, 'font-bold text-muted' => $item->read])>{{ $item->title }}@unless ($item->read)<span class="sr-only"> {{ __('(unread)') }}</span>@endunless</span>
                                @if ($item->body !== '')
                                    <span class="mt-0.5 block text-sm text-muted">{{ $item->body }}</span>
                                @endif
                            </span>
                            <time class="shrink-0 text-xs text-muted" datetime="{{ $item->at->toIso8601String() }}">{{ $item->at->diffForHumans() }}</time>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
        @if ($items->hasMorePages() || ! $items->onFirstPage())
            <nav class="flex justify-between gap-3" aria-label="{{ __('Notification pages') }}">
                <x-signal.ui.button :href="$items->previousPageUrl()" :disabled="$items->onFirstPage()" variant="secondary">{{ __('Newer') }}</x-signal.ui.button>
                <x-signal.ui.button :href="$items->nextPageUrl()" :disabled="! $items->hasMorePages()" variant="secondary">{{ __('Older') }}</x-signal.ui.button>
            </nav>
        @endif
    @endif
</x-signal.layouts.app>
