@props(['navigation' => []])

<section
    id="app-mobile-nav"
    data-mobile-navigation
    x-cloak
    x-show="menu"
    x-trap.inert.noscroll="menu"
    @resize.window="if (window.innerWidth >= 1024) menu = false"
    class="fixed inset-0 z-50 lg:hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="app-mobile-nav-title"
>
    <button type="button" class="absolute inset-0 bg-slate-950/50" aria-label="{{ __('Close navigation') }}" @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())"></button>
    <aside class="relative flex h-full w-72 flex-col overflow-y-auto bg-surface px-4 py-5 shadow-2xl">
        <h2 id="app-mobile-nav-title" class="sr-only">{{ __('Application navigation') }}</h2>
        <button type="button" x-ref="closeNavigation" class="ui-icon-btn absolute right-3 top-3 z-10" aria-label="{{ __('Close navigation') }}" @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())">
            <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#close"></use></svg>
        </button>
        <x-layouts.navigation-content :navigation="$navigation" :mobile="true" />
    </aside>
</section>
