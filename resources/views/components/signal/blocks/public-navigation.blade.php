@props(['activeProduct' => null])

@php($signedIn = auth('platform')->check())

<header class="sticky top-0 z-40 border-b border-line bg-surface/95 shadow-soft backdrop-blur">
    <div class="mx-auto flex min-h-16 max-w-screen-2xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <a href="{{ route('core.entry') }}" class="flex shrink-0 items-center gap-3 text-sm font-extrabold tracking-tight text-ink" aria-label="{{ __('Buildpusher home') }}">
            <span class="grid size-9 place-items-center rounded-xl bg-ink text-surface shadow-soft">
                <img src="{{ asset('favicon.svg') }}" alt="" class="size-5 rounded-md">
            </span>
            <span>Buildpusher</span>
        </a>

        <nav class="hidden items-center gap-1 lg:flex" aria-label="{{ __('Product navigation') }}">
            <a href="{{ route('core.entry') }}#products" class="rounded-control px-3 py-2 text-sm font-semibold text-muted transition hover:bg-surface-muted hover:text-ink">{{ __('Products') }}</a>
            @foreach (['deployer' => 'Deployer', 'monitor' => 'Monitor', 'analytics' => 'Analytics'] as $slug => $label)
                <a href="{{ route('core.marketing.product', $slug) }}" class="rounded-control px-3 py-2 text-sm font-semibold transition hover:bg-surface-muted hover:text-ink {{ $activeProduct === $slug ? 'bg-surface-muted text-ink' : 'text-muted' }}" @if ($activeProduct === $slug) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            <a href="{{ route('core.pricing') }}" class="rounded-control px-3 py-2 text-sm font-semibold text-muted transition hover:bg-surface-muted hover:text-ink" @if (request()->routeIs('core.pricing')) aria-current="page" @endif>{{ __('Pricing') }}</a>
            <a href="{{ route('core.access-request.create') }}" class="rounded-control px-3 py-2 text-sm font-semibold text-muted transition hover:bg-surface-muted hover:text-ink" @if (request()->routeIs('core.access-request.*')) aria-current="page" @endif>{{ __('Request access') }}</a>
            <a href="{{ route('core.help') }}" class="rounded-control px-3 py-2 text-sm font-semibold text-muted transition hover:bg-surface-muted hover:text-ink" @if (request()->routeIs('core.help')) aria-current="page" @endif>{{ __('Help') }}</a>
            <a href="{{ route('core.status') }}" class="rounded-control px-3 py-2 text-sm font-semibold text-muted transition hover:bg-surface-muted hover:text-ink" @if (request()->routeIs('core.status')) aria-current="page" @endif>{{ __('Status') }}</a>
        </nav>

        <div class="flex shrink-0 items-center gap-2">
            <details class="group relative lg:hidden">
                <summary class="ui-icon-btn cursor-pointer list-none" aria-label="{{ __('Open product navigation') }}">
                    <svg class="size-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>
                </summary>
                <nav class="absolute right-0 top-full z-40 mt-2 grid w-56 gap-1 rounded-panel border border-line bg-surface p-2 shadow-panel" aria-label="{{ __('Mobile product navigation') }}">
                    <a href="{{ route('core.entry') }}#products" class="rounded-control px-3 py-2 text-sm font-semibold text-muted hover:bg-surface-muted hover:text-ink">{{ __('All products') }}</a>
                    @foreach (['deployer' => 'Deployer', 'monitor' => 'Monitor', 'analytics' => 'Analytics'] as $slug => $label)
                        <a href="{{ route('core.marketing.product', $slug) }}" class="rounded-control px-3 py-2 text-sm font-semibold text-muted hover:bg-surface-muted hover:text-ink">{{ $label }}</a>
                    @endforeach
                    <a href="{{ route('core.pricing') }}" class="rounded-control px-3 py-2 text-sm font-semibold text-muted hover:bg-surface-muted hover:text-ink">{{ __('Pricing') }}</a>
                    <a href="{{ route('core.access-request.create') }}" class="rounded-control px-3 py-2 text-sm font-semibold text-muted hover:bg-surface-muted hover:text-ink">{{ __('Request Deployer access') }}</a>
                    <a href="{{ route('core.help') }}" class="rounded-control px-3 py-2 text-sm font-semibold text-muted hover:bg-surface-muted hover:text-ink">{{ __('Help and guides') }}</a>
                    <a href="{{ route('core.status') }}" class="rounded-control px-3 py-2 text-sm font-semibold text-muted hover:bg-surface-muted hover:text-ink">{{ __('Platform status') }}</a>
                </nav>
            </details>
            <button type="button" class="ui-icon-btn hidden sm:inline-flex" data-theme-toggle aria-label="{{ __('Change appearance') }}" aria-pressed="false">
                <svg class="size-[19px] stroke-2 dark:hidden" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#moon"></use></svg>
                <svg class="hidden size-[19px] stroke-2 dark:block" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#sun"></use></svg>
            </button>
            @if ($signedIn)
                <x-signal.ui.button :href="route('core.home')" variant="primary" class="ui-btn-sm">{{ __('Open workspace') }}</x-signal.ui.button>
            @else
                <x-signal.ui.button :href="route('platform.login')" variant="secondary" class="ui-btn-sm hidden sm:inline-flex">{{ __('Sign in') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('platform.register')" variant="primary" class="ui-btn-sm">{{ __('Create workspace') }}</x-signal.ui.button>
            @endif
        </div>
    </div>
</header>
