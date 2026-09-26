@props([
    'title',
    'description' => null,
])

{{-- The signed-in frame. ShellComposer supplies $shell (switchers and sidebar) from the current user and route. --}}
<x-signal.layouts.base :title="$title" :description="$description">
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>

    <header data-mobile-header class="sticky top-0 z-40 border-b border-line bg-surface">
        <div class="flex h-[var(--header-height)] items-center gap-2 px-3 sm:gap-3 sm:px-4 lg:px-6">
            <button type="button" class="ui-icon-btn shrink-0 lg:hidden" data-mobile-toggle aria-controls="app-navigation-drawer" aria-expanded="false" aria-label="{{ __('Open navigation') }}">
                <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>
            </button>
            <a href="{{ route('dashboard') }}" class="inline-flex shrink-0 items-center gap-2 rounded-control font-extrabold tracking-tight text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-focus">
                <span class="grid h-8 w-8 place-items-center rounded-card bg-ink text-surface" aria-hidden="true">↗</span>
                <span class="hidden sm:inline">{{ config('app.name') }}</span>
            </a>

            @isset($shell)
                <div class="hidden min-w-0 items-center gap-2 lg:flex">
                    <x-signal.layouts.switchers :shell="$shell" />
                </div>

                <x-signal.ui.menu align="right" class="ml-auto" trigger-class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-control px-2 text-sm font-bold text-ink hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden" panel-class="min-w-56" data-signal-menu @click.outside="$el.open = false">
                    <x-slot:trigger>
                        <x-signal.ui.avatar :name="$shell->user->name" class="h-8 w-8 text-xs" />
                        <span class="hidden max-w-40 truncate md:inline">{{ $shell->user->name }}</span>
                        <span class="sr-only">{{ __('Your menu') }}</span>
                    </x-slot:trigger>
                    <div class="grid gap-1 p-2">
                        <p class="truncate px-3 py-2 text-xs text-muted">{{ $shell->user->email }}</p>
                        <a href="{{ route('settings.profile') }}" class="rounded-control px-3 py-2 text-sm font-bold text-ink hover:bg-surface-muted">{{ __('Your settings') }}</a>
                        <a href="{{ route('settings.security') }}" class="rounded-control px-3 py-2 text-sm font-bold text-ink hover:bg-surface-muted">{{ __('Security') }}</a>
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-line pt-1">
                            @csrf
                            <button type="submit" class="w-full rounded-control px-3 py-2 text-left text-sm font-bold text-ink hover:bg-surface-muted">{{ __('Sign out') }}</button>
                        </form>
                    </div>
                </x-signal.ui.menu>
            @endisset
        </div>
    </header>

    <div class="flex">
        @isset($shell)
            <aside id="app-sidebar" data-desktop-navigation class="sticky top-[var(--header-height)] hidden h-[calc(100dvh-var(--header-height))] w-[var(--sidebar-width)] shrink-0 overflow-y-auto border-r border-line bg-surface px-3 py-5 lg:block">
                <x-signal.layouts.sidebar-nav :shell="$shell" />
            </aside>
        @endisset
        <main id="main-content" tabindex="-1" class="min-w-0 flex-1">
            <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-8 sm:px-6">
                {{ $slot }}
            </div>
        </main>
    </div>

    @isset($shell)
        <x-signal.layouts.mobile-sidebar id="app-navigation-drawer" :title="__('Navigation')" :brand-url="route('dashboard')" desktop-navigation="#app-sidebar">
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <x-signal.layouts.switchers :shell="$shell" variant="mobile" />
                </div>
                <x-signal.layouts.sidebar-nav :shell="$shell" />
            </div>
        </x-signal.layouts.mobile-sidebar>
    @endisset
</x-signal.layouts.base>
