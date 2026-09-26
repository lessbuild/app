@props([
    'navigationLabel' => 'Primary navigation',
    'mobileNavigationLabel' => 'Mobile navigation',
    'mobileNavigationId' => 'navbarCollapse',
    'mobileToggleId' => 'navbarToggler',
])

@php
    $registrationOpen = app(\App\Modules\Deployer\Services\RegistrationAccess::class)->allowsNewUser();
    $publicLinks = [
        ['label' => __('Capabilities'), 'href' => '#features'],
        ['label' => __('Product'), 'href' => '#product'],
        ['label' => __('How it works'), 'href' => '#how-it-works'],
        ['label' => __('Pricing'), 'href' => route('pricing')],
        ['label' => __('Status'), 'href' => route('platform-status.show')],
        ['label' => __('Questions'), 'href' => '#questions'],
    ];
@endphp

<header class="relative z-40 border-b border-line bg-surface/90 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-content items-center justify-between gap-6 px-5 sm:px-8">
        <a href="{{ url('/') }}" class="flex shrink-0 items-center gap-3 text-base font-extrabold tracking-tight text-ink" aria-label="{{ config('app.name') }} home">
            <span class="grid h-9 w-9 place-items-center rounded-xl bg-ink text-surface shadow-soft">
                <img src="{{ asset('favicon.svg') }}" alt="" class="h-5 w-5 rounded-md">
            </span>
            <span>{{ config('app.name') }}</span>
        </a>

        <nav data-desktop-navigation class="hidden items-center gap-1 md:flex" aria-label="{{ __($navigationLabel) }}">
            @foreach ($publicLinks as $link)
                <x-signal.ui.link :href="$link['href']" variant="muted" size="inline" class="px-3 py-2">{{ $link['label'] }}</x-signal.ui.link>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <x-signal.ui.button :href="route('login')" variant="secondary" size="sm" class="hidden sm:inline-flex">{{ __('Sign in') }}</x-signal.ui.button>
            <x-signal.ui.button :href="$registrationOpen ? route('register') : route('access-request.create')" variant="primary" size="sm" class="hidden sm:inline-flex">{{ $registrationOpen ? __('Get started') : __('Request access') }}</x-signal.ui.button>
            <x-signal.ui.icon-button label="{{ __('Use dark theme') }}" data-theme-toggle aria-pressed="false">
                <svg class="h-[19px] w-[19px] stroke-2 dark:hidden" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#moon"></use></svg>
                <svg class="hidden h-[19px] w-[19px] stroke-2 dark:block" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#sun"></use></svg>
            </x-signal.ui.icon-button>
            <x-signal.ui.icon-button :label="__('Open navigation')" id="{{ $mobileToggleId }}" class="md:hidden" data-mobile-toggle aria-controls="{{ $mobileNavigationId }}" aria-expanded="false">
                <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>
            </x-signal.ui.icon-button>
        </div>
    </div>
</header>

<div id="{{ $mobileNavigationId }}" class="fixed inset-0 z-50 hidden md:hidden" data-mobile-drawer role="dialog" aria-modal="true" aria-labelledby="{{ $mobileNavigationId }}-title" aria-hidden="true">
    <x-signal.overlays.backdrop-button label="{{ __('Close navigation') }}" class="absolute inset-0 bg-slate-950/50" data-mobile-toggle />
    <div class="relative ml-auto flex h-full w-[min(21rem,88vw)] flex-col overflow-y-auto border-l border-line bg-surface px-5 py-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <span id="{{ $mobileNavigationId }}-title" class="text-sm font-extrabold text-ink">{{ __('Explore :app', ['app' => config('app.name')]) }}</span>
            <x-signal.ui.icon-button label="{{ __('Close navigation') }}" data-mobile-toggle>
                <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#close"></use></svg>
            </x-signal.ui.icon-button>
        </div>
        <nav class="mt-8 flex flex-col gap-1" aria-label="{{ __($mobileNavigationLabel) }}">
            @foreach ($publicLinks as $link)
                <x-signal.ui.link :href="$link['href']" variant="muted" class="w-full py-3">{{ $link['label'] }}</x-signal.ui.link>
            @endforeach
            <x-signal.ui.button :href="route('login')" variant="secondary" class="mt-5 w-full">{{ __('Sign in') }}</x-signal.ui.button>
            <x-signal.ui.button :href="$registrationOpen ? route('register') : route('access-request.create')" variant="primary" class="w-full">{{ $registrationOpen ? __('Get started') : __('Request access') }}</x-signal.ui.button>
        </nav>
        <x-signal.ui.panel class="mt-auto bg-surface-muted p-4">
            <p class="text-xs font-bold text-ink">{{ __('Your infrastructure. One focused control plane.') }}</p>
            <p class="mt-1 text-xs leading-5 text-muted">{{ __('Provision, deploy, observe, and recover from one workspace.') }}</p>
        </x-signal.ui.panel>
    </div>
</div>

<noscript>
    <nav class="border-b border-line bg-surface px-5 py-3 md:hidden" aria-label="{{ __('Navigation without JavaScript') }}">
        <div class="flex flex-wrap gap-2">
            @foreach ($publicLinks as $link)
                <x-signal.ui.button :href="$link['href']" variant="secondary" size="sm">{{ $link['label'] }}</x-signal.ui.button>
            @endforeach
            <x-signal.ui.button :href="$registrationOpen ? route('register') : route('access-request.create')" variant="primary" size="sm">{{ $registrationOpen ? __('Get started') : __('Request access') }}</x-signal.ui.button>
        </div>
    </nav>
</noscript>
