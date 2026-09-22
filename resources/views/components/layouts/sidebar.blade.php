@props(['navigation' => []])

<div
    id="desktop-navigation"
    x-cloak
    role="navigation"
    aria-label="{{ __('Primary navigation') }}"
    class="app-sidebar fixed inset-y-0 left-0 z-50 hidden h-screen w-64 flex-col overflow-y-auto overscroll-contain pb-4 lg:flex"
    @click="if ($event.target.closest('a')) menu = false"
>
    <div class="app-sidebar__header sticky top-0 z-10 flex h-14 w-full shrink-0 items-center justify-between px-4">
        <a href="{{ route('dashboard') }}" class="app-sidebar__brand min-w-0" aria-label="{{ config('app.name') }}">
            <span class="app-sidebar__brand-mark" aria-hidden="true">{{ str(config('app.name'))->substr(0, 1) }}</span>
            <span class="truncate">{{ config('app.name') }}</span>
        </a>
        <button
            type="button"
            x-ref="desktopCloseNavigation"
            class="ui-btn ui-btn-secondary lg:hidden"
            aria-label="{{ __('Close navigation') }}"
            @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())"
        >
            <svg class="h-4 w-4 stroke-2 text-muted" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#chevron-left"></use>
            </svg>
        </button>
    </div>

    <a
        href="{{ route('search.index') }}"
        data-workspace-search-trigger
        class="app-sidebar__search mx-3 my-4 flex min-h-11 items-center justify-between gap-3 px-3 text-sm"
        @click.prevent="openPalette($event.currentTarget)"
    >
        <span>{{ __('Search or jump to…') }}</span>
        <kbd class="ui-kbd">⌘K</kbd>
    </a>

    <div class="app-sidebar__groups space-y-5 px-3 pb-4">
        @foreach ($navigation['groups'] ?? [] as $group)
            <section aria-labelledby="desktop-navigation-{{ $loop->index }}">
                <h2 id="desktop-navigation-{{ $loop->index }}" class="app-sidebar__label mb-1 px-3">
                    {{ $group['label'] }}
                </h2>
                <nav class="app-sidebar__nav space-y-1" aria-label="{{ $group['label'] }}">
                    @foreach ($group['items'] as $item)
                        <x-layouts.partials.navigation-link :item="$item" />
                    @endforeach
                </nav>
            </section>
        @endforeach
    </div>

    <div class="app-sidebar__footer mt-auto space-y-5 px-3">
        <section aria-labelledby="desktop-navigation-help">
            <h2 id="desktop-navigation-help" class="app-sidebar__label mb-1 px-3">
                {{ __('Help') }}
            </h2>
            <nav class="app-sidebar__nav space-y-1" aria-label="{{ __('Help') }}">
                @foreach ($navigation['support'] ?? [] as $item)
                    <x-layouts.partials.navigation-link :item="$item" />
                @endforeach
            </nav>
        </section>

        <section class="app-sidebar__workspace border-t pt-4" aria-labelledby="desktop-navigation-workspace">
            <h2 id="desktop-navigation-workspace" class="app-sidebar__label mb-1 px-3">
                {{ __('Workspace') }}
            </h2>
            <nav class="app-sidebar__nav space-y-1" aria-label="{{ __('Workspace') }}">
                @foreach ($navigation['profile'] ?? [] as $item)
                    <x-layouts.partials.navigation-link :item="$item" />
                @endforeach
            </nav>
        </section>

        <div data-mobile-account class="mx-4 mt-4 border-t border-line pt-4 lg:hidden">
            <div class="flex min-w-0 items-center gap-3">
                <x-avatar :name="auth()->user()->name" class="h-9 w-9 rounded-lg text-xs" />
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-ink">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-muted">{{ auth()->user()->email }}</p>
                </div>
            </div>
            <form action="{{ route('logout') }}" method="post" class="mt-4">
                @csrf
            <button type="submit" class="ui-btn ui-btn-secondary w-full justify-center">{{ __('Logout') }}</button>
            </form>
        </div>
    </div>
</div>
