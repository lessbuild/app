@props(['navigation' => []])

<section
    id="primary-navigation"
    data-mobile-navigation
    x-cloak
    x-show="menu"
    x-trap.inert.noscroll="menu"
    @resize.window="if (window.innerWidth >= 1024) menu = false"
    class="app-mobile-navigation fixed inset-0 z-[60] flex h-[100dvh] flex-col lg:hidden"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('Primary navigation') }}"
>
    <div class="app-mobile-navigation__header flex shrink-0 items-center gap-3 px-4 py-3">
        <a href="{{ route('dashboard') }}" class="app-mobile-navigation__brand max-w-[35%] truncate" aria-label="{{ config('app.name') }}">
            <span class="app-sidebar__brand-mark" aria-hidden="true">{{ str(config('app.name'))->substr(0, 1) }}</span>
            <span class="truncate">{{ config('app.name') }}</span>
        </a>
        <a
            href="{{ route('search.index') }}"
            data-workspace-search-trigger
            class="app-mobile-navigation__search flex h-11 min-w-0 flex-1 items-center px-3 text-sm"
            @click.prevent="openPalette($event.currentTarget)"
        >
            {{ __('Search workspace') }}
        </a>
        <button type="button" x-ref="closeNavigation" class="ui-icon-btn h-11 w-11 shrink-0 text-2xl" aria-label="{{ __('Close navigation') }}" @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())">×</button>
    </div>

    <div class="app-mobile-navigation__body min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
        <a href="{{ route('organizations.index') }}" class="app-mobile-navigation__workspace mb-4 flex items-center gap-3 p-3">
            <x-avatar :name="auth()->user()->currentOrganization?->name ?: config('app.name')" class="h-10 w-10 shrink-0 rounded-lg" />
            <span class="min-w-0">
                <span class="block text-[10px] font-bold uppercase tracking-wide text-muted">{{ __('Current workspace') }}</span>
                <span class="block truncate text-sm font-bold text-ink">{{ auth()->user()->currentOrganization?->name ?: config('app.name') }}</span>
            </span>
        </a>

        @foreach ($navigation['mobile']['groups'] ?? [] as $group)
            <nav class="app-mobile-navigation__nav grid grid-cols-2 gap-2 border-b pb-4 mb-4" aria-label="{{ $loop->first ? __('Workspace navigation') : __('Settings and support') }}">
                @foreach ($group as $item)
                    <x-layouts.partials.navigation-link :item="$item" mobile />
                @endforeach
            </nav>
        @endforeach

        <form method="POST" action="{{ route('logout') }}" class="mt-4">
            @csrf
            <x-ui.button type="submit" variant="secondary" class="min-h-[44px] w-full">{{ __('Logout') }}</x-ui.button>
        </form>
    </div>
</section>
