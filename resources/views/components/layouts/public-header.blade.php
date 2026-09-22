@props([
    'navigationLabel' => 'Primary navigation',
    'mobileNavigationLabel' => 'Mobile navigation',
    'mobileNavigationId' => 'navbarCollapse',
    'mobileToggleId' => 'navbarToggler',
])

@php
    $registrationOpen = app(\App\Services\RegistrationAccess::class)->allowsNewUser();
    $publicLinks = [
        ['label' => __('Capabilities'), 'href' => '#features'],
        ['label' => __('Product'), 'href' => '#product'],
        ['label' => __('How it works'), 'href' => '#how-it-works'],
        ['label' => __('Pricing'), 'href' => route('pricing')],
        ['label' => __('Status'), 'href' => route('platform-status.show')],
        ['label' => __('Questions'), 'href' => '#questions'],
    ];
@endphp

<header class="relative z-40 border-b border-line bg-surface/90 backdrop-blur" x-data="{ navigationOpen: false }" @keydown.escape.window="navigationOpen = false">
    <div class="mx-auto flex h-16 max-w-content items-center justify-between gap-6 px-5 sm:px-8">
        <a href="{{ url('/') }}" class="flex shrink-0 items-center gap-3 text-base font-extrabold tracking-tight text-ink" aria-label="{{ config('app.name') }} home">
            <span class="grid h-9 w-9 place-items-center rounded-xl bg-ink text-surface shadow-soft">
                <img src="{{ asset('favicon.svg') }}" alt="" class="h-5 w-5 rounded-md">
            </span>
            <span>{{ config('app.name') }}</span>
        </a>

        <nav class="hidden items-center gap-1 md:flex" aria-label="{{ __($navigationLabel) }}">
            @foreach ($publicLinks as $link)
                <a href="{{ $link['href'] }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-muted transition hover:bg-surface-muted hover:text-ink">{{ $link['label'] }}</a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <x-ui.button :href="route('login')" variant="secondary" class="ui-btn-sm hidden sm:inline-flex">{{ __('Sign in') }}</x-ui.button>
            <x-ui.button :href="$registrationOpen ? route('register') : route('access-request.create')" variant="primary" class="ui-btn-sm hidden sm:inline-flex">{{ $registrationOpen ? __('Get started') : __('Request access') }}</x-ui.button>
            <button type="button" class="ui-icon-btn" data-theme-toggle aria-label="{{ __('Use dark theme') }}" aria-pressed="false">
                <svg class="h-[19px] w-[19px] stroke-2 dark:hidden" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#moon"></use></svg>
                <svg class="hidden h-[19px] w-[19px] stroke-2 dark:block" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#sun"></use></svg>
            </button>
            <button id="{{ $mobileToggleId }}" type="button" class="ui-icon-btn md:hidden" aria-controls="{{ $mobileNavigationId }}" :aria-expanded="navigationOpen.toString()" aria-label="{{ __('Open navigation') }}" @click="navigationOpen = ! navigationOpen">
                <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>
            </button>
        </div>
    </div>
</header>

<div id="{{ $mobileNavigationId }}" x-cloak x-show="navigationOpen" x-trap.inert.noscroll="navigationOpen" class="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true" aria-labelledby="{{ $mobileNavigationId }}-title" aria-hidden="true">
    <button type="button" class="absolute inset-0 bg-slate-950/50" aria-label="{{ __('Close navigation') }}" @click="navigationOpen = false"></button>
    <div class="relative ml-auto flex h-full w-[min(21rem,88vw)] flex-col overflow-y-auto border-l border-line bg-surface px-5 py-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <span id="{{ $mobileNavigationId }}-title" class="text-sm font-extrabold text-ink">{{ __('Explore :app', ['app' => config('app.name')]) }}</span>
            <button type="button" class="ui-icon-btn" aria-label="{{ __('Close navigation') }}" @click="navigationOpen = false">×</button>
        </div>
        <nav class="mt-8 flex flex-col gap-1" aria-label="{{ __($mobileNavigationLabel) }}" @click="if ($event.target.closest('a')) navigationOpen = false">
            @foreach ($publicLinks as $link)
                <a href="{{ $link['href'] }}" class="rounded-xl px-3 py-3 text-sm font-bold text-muted transition hover:bg-surface-muted hover:text-ink">{{ $link['label'] }}</a>
            @endforeach
            <a href="{{ route('login') }}" class="ui-btn ui-btn-secondary mt-5 w-full">{{ __('Sign in') }}</a>
            <a href="{{ $registrationOpen ? route('register') : route('access-request.create') }}" class="ui-btn ui-btn-primary w-full">{{ $registrationOpen ? __('Get started') : __('Request access') }}</a>
        </nav>
        <div class="mt-auto rounded-panel border border-line bg-surface-muted p-4">
            <p class="text-xs font-bold text-ink">{{ __('Your infrastructure. One focused control plane.') }}</p>
            <p class="mt-1 text-xs leading-5 text-muted">{{ __('Provision, deploy, observe, and recover from one workspace.') }}</p>
        </div>
    </div>
</div>

<noscript>
    <nav class="border-b border-line bg-surface px-5 py-3 md:hidden" aria-label="{{ __('Navigation without JavaScript') }}">
        <div class="flex flex-wrap gap-2">
            @foreach ($publicLinks as $link)
                <a href="{{ $link['href'] }}" class="ui-btn ui-btn-secondary ui-btn-sm">{{ $link['label'] }}</a>
            @endforeach
            <a href="{{ $registrationOpen ? route('register') : route('access-request.create') }}" class="ui-btn ui-btn-primary ui-btn-sm">{{ $registrationOpen ? __('Get started') : __('Request access') }}</a>
        </div>
    </nav>
</noscript>
