@props(['navigation' => []])

<section
    id="primary-navigation"
    data-mobile-navigation
    x-cloak
    x-show="menu"
    x-trap.inert.noscroll="menu"
    @resize.window="if (window.innerWidth >= 1024) menu = false"
    class="fixed inset-0 z-[60] flex h-[100dvh] flex-col bg-page lg:hidden"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('Primary navigation') }}"
>
    <div class="flex shrink-0 items-center gap-3 border-b border-line bg-surface px-4 py-3 text-ink shadow-soft">
        <a href="{{ route('dashboard') }}" class="max-w-[35%] truncate text-sm font-bold text-ink">{{ config('app.name') }}</a>
        <a
            href="{{ route('search.index') }}"
            data-workspace-search-trigger
            class="flex h-11 min-w-0 flex-1 items-center rounded-lg border border-primary bg-secondary px-3 text-sm text-secondary"
            @click.prevent="openPalette($event.currentTarget)"
        >
            {{ __('Search workspace') }}
        </a>
        <button type="button" x-ref="closeNavigation" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-2xl text-secondary hover:bg-secondary hover:text-primary" aria-label="{{ __('Close navigation') }}" @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())">×</button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
        <a href="{{ route('organizations.index') }}" class="mb-4 flex items-center gap-3 rounded-xl border border-primary p-3 shadow-xs">
            <x-avatar :name="auth()->user()->currentOrganization?->name ?: config('app.name')" class="h-10 w-10 shrink-0 rounded-lg" />
            <span class="min-w-0">
                <span class="block text-[10px] font-bold uppercase tracking-wide text-secondary">{{ __('Current workspace') }}</span>
                <span class="block truncate text-sm font-bold text-primary">{{ auth()->user()->currentOrganization?->name ?: config('app.name') }}</span>
            </span>
        </a>

        @foreach ($navigation['mobile']['groups'] ?? [] as $group)
            <nav class="grid grid-cols-2 gap-2 border-b border-primary pb-4 mb-4" aria-label="{{ $loop->first ? __('Workspace navigation') : __('Settings and support') }}">
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
