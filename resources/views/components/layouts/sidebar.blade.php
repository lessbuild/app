@props(['navigation' => []])

<div
    id="desktop-navigation"
    x-cloak
    role="navigation"
    aria-label="{{ __('Primary navigation') }}"
    class="fixed inset-y-0 left-0 z-50 hidden h-screen w-64 flex-col overflow-y-auto overscroll-contain border-r border-primary bg-primary pb-4 lg:flex"
    @click="if ($event.target.closest('a')) menu = false"
>
    <div class="sticky top-0 z-10 flex h-14 w-full shrink-0 items-center justify-between border-b border-primary bg-primary px-4">
        <a href="{{ route('dashboard') }}" class="truncate pl-2 text-lg font-bold leading-tight text-primary">
            {{ config('app.name') }}
        </a>
        <button
            type="button"
            x-ref="desktopCloseNavigation"
            class="button tertiary lg:hidden"
            aria-label="{{ __('Close navigation') }}"
            @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())"
        >
            <svg class="h-4 w-4 stroke-2 text-secondary" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#chevron-left"></use>
            </svg>
        </button>
    </div>

    <form method="GET" action="{{ route('search.index') }}" class="flex gap-2 px-3 py-4">
        <label for="global-search" class="sr-only">{{ __('Search account') }}</label>
        <input
            id="global-search"
            name="q"
            type="search"
            maxlength="100"
            value="{{ request()->routeIs('search.index') ? request()->string('q') : '' }}"
            placeholder="{{ __('Search or jump to…') }}"
            class="input secondary min-w-0 flex-1 rounded-lg"
        >
        <button type="submit" class="button primary">{{ __('Go') }}</button>
    </form>

    <div class="space-y-5 px-3 pb-4">
        @foreach ($navigation['groups'] ?? [] as $group)
            <section aria-labelledby="desktop-navigation-{{ $loop->index }}">
                <h2 id="desktop-navigation-{{ $loop->index }}" class="mb-1 px-3 text-[10px] font-bold uppercase tracking-widest text-secondary">
                    {{ $group['label'] }}
                </h2>
                <nav class="space-y-1" aria-label="{{ $group['label'] }}">
                    @foreach ($group['items'] as $item)
                        <x-layouts.partials.navigation-link :item="$item" />
                    @endforeach
                </nav>
            </section>
        @endforeach
    </div>

    <div class="mt-auto space-y-5 px-3">
        <section aria-labelledby="desktop-navigation-help">
            <h2 id="desktop-navigation-help" class="mb-1 px-3 text-[10px] font-bold uppercase tracking-widest text-secondary">
                {{ __('Help') }}
            </h2>
            <nav class="space-y-1" aria-label="{{ __('Help') }}">
                @foreach ($navigation['support'] ?? [] as $item)
                    <x-layouts.partials.navigation-link :item="$item" />
                @endforeach
            </nav>
        </section>

        <section class="border-t border-primary pt-4" aria-labelledby="desktop-navigation-workspace">
            <h2 id="desktop-navigation-workspace" class="mb-1 px-3 text-[10px] font-bold uppercase tracking-widest text-secondary">
                {{ __('Workspace') }}
            </h2>
            <nav class="space-y-1" aria-label="{{ __('Workspace') }}">
                @foreach ($navigation['profile'] ?? [] as $item)
                    <x-layouts.partials.navigation-link :item="$item" />
                @endforeach
            </nav>
        </section>

        <div data-mobile-account class="border-t border-primary px-3 pt-4 lg:hidden">
            <div class="flex min-w-0 items-center gap-3">
                <x-avatar :name="auth()->user()->name" class="h-9 w-9 rounded-lg text-xs" />
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-primary">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-secondary">{{ auth()->user()->email }}</p>
                </div>
            </div>
            <form action="{{ route('logout') }}" method="post" class="mt-4">
                @csrf
                <button type="submit" class="button tertiary w-full justify-center">{{ __('Logout') }}</button>
            </form>
        </div>
    </div>
</div>
