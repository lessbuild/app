@props([
    'title',
    'description' => null,
])

{{-- Interim signed-in frame; Phase 2 replaces the header with the full topbar and project sidebar. --}}
<x-signal.layouts.base :title="$title" :description="$description">
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <header class="border-b border-line bg-surface">
        <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-3 rounded-control font-extrabold tracking-tight text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-focus">
                <span class="grid h-8 w-8 place-items-center rounded-card bg-ink text-surface" aria-hidden="true">↗</span>
                <span>{{ config('app.name') }}</span>
            </a>
            <nav aria-label="{{ __('Main') }}" class="flex flex-wrap items-center gap-2">
                <x-signal.ui.button :href="route('dashboard')" variant="quiet" size="sm" :aria-current="request()->routeIs('dashboard') ? 'page' : null">{{ __('Dashboard') }}</x-signal.ui.button>
                @if (auth()->user()?->current_account_id)
                    <x-signal.ui.button :href="route('account.members')" variant="quiet" size="sm" :aria-current="request()->routeIs('account.*') ? 'page' : null">{{ __('Account') }}</x-signal.ui.button>
                @endif
                <x-signal.ui.button :href="route('settings.profile')" variant="quiet" size="sm" :aria-current="request()->routeIs('settings.*') ? 'page' : null">{{ __('Settings') }}</x-signal.ui.button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Sign out') }}</x-signal.ui.button>
                </form>
            </nav>
        </div>
    </header>
    <main id="main-content" tabindex="-1" class="mx-auto w-full max-w-5xl space-y-6 px-4 py-8 sm:px-6">
        {{ $slot }}
    </main>
</x-signal.layouts.base>
