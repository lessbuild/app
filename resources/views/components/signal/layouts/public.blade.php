@props([
    'title',
    'description' => null,
    'canonical' => null,
])

@php($services = app(\App\Platform\ServiceRegistry::class)->all())

{{-- The public site, as the Signal starter's Buildpusher homepage lays it out: a blurred sticky header, the page, and a three-column footer. Indexed by search engines. --}}
<x-signal.layouts.base :title="$title" :description="$description" indexable :canonical="$canonical">
    <div class="min-h-screen overflow-x-hidden">
        <header class="sticky top-0 z-40 border-b border-line bg-surface/90 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-6 px-5 sm:px-8">
                <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-3 text-base font-extrabold tracking-tight text-ink" aria-label="{{ __(':app home', ['app' => config('app.name')]) }}">
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-ink text-surface shadow-soft" aria-hidden="true"><x-signal.ui.icon name="layers" class="h-5 w-5" /></span>
                    <span>{{ config('app.name') }}</span>
                </a>
                <nav class="hidden items-center gap-1 md:flex" aria-label="{{ __('Primary navigation') }}">
                    @foreach ($services as $service)
                        <a href="{{ route('features', $service->key()) }}" @class(['rounded-lg px-3 py-2 text-sm font-semibold transition hover:bg-surface-muted', 'text-ink' => request()->route('service') === $service->key(), 'text-muted' => request()->route('service') !== $service->key()]) @if (request()->routeIs('features') && request()->route('service') === $service->key()) aria-current="page" @endif>{{ $service->name() }}</a>
                    @endforeach
                    <a href="{{ route('pricing') }}" @class(['rounded-lg px-3 py-2 text-sm font-semibold transition hover:bg-surface-muted', 'text-ink' => request()->routeIs('pricing'), 'text-muted' => ! request()->routeIs('pricing')]) @if (request()->routeIs('pricing')) aria-current="page" @endif>{{ __('Pricing') }}</a>
                    <a href="{{ route('docs.api') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-muted transition hover:bg-surface-muted">{{ __('API') }}</a>
                </nav>
                <div class="flex items-center gap-2">
                    <a href="{{ route('login') }}" class="ui-btn ui-btn-ghost ui-btn-sm hidden sm:inline-flex">{{ __('Sign in') }}</a>
                    <a href="{{ route('register') }}" class="ui-btn ui-btn-primary ui-btn-sm">{{ __('Start free') }}</a>
                    <x-signal.ui.icon-button :label="__('Use dark theme')" data-theme-toggle aria-pressed="false">
                        <x-signal.ui.icon name="moon" class="h-5 w-5 dark:hidden" /><x-signal.ui.icon name="sun" class="hidden h-5 w-5 dark:block" />
                    </x-signal.ui.icon-button>
                    <details class="relative md:hidden">
                        <summary class="ui-icon-btn list-none" aria-label="{{ __('Open navigation') }}"><x-signal.ui.icon name="menu" class="h-5 w-5" /></summary>
                        <nav class="absolute right-0 top-12 grid w-64 gap-1 rounded-panel border border-line bg-surface p-3 shadow-panel" aria-label="{{ __('Mobile navigation') }}">
                            @foreach ($services as $service)
                                <a href="{{ route('features', $service->key()) }}" class="rounded-xl px-3 py-2.5 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ $service->name() }}</a>
                            @endforeach
                            <a href="{{ route('pricing') }}" class="rounded-xl px-3 py-2.5 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ __('Pricing') }}</a>
                            <a href="{{ route('docs.api') }}" class="rounded-xl px-3 py-2.5 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ __('API') }}</a>
                            <a href="{{ route('login') }}" class="ui-btn ui-btn-secondary mt-2 w-full">{{ __('Sign in') }}</a>
                        </nav>
                    </details>
                </div>
            </div>
        </header>

        <main id="main-content" tabindex="-1">
            {{ $slot }}
        </main>

        <footer class="border-t border-line bg-surface">
            <div class="mx-auto grid max-w-6xl gap-10 px-5 py-12 sm:px-8 md:grid-cols-[1.4fr_1fr_1fr] md:py-16">
                <div>
                    <a href="{{ route('home') }}" class="flex items-center gap-3 text-base font-extrabold tracking-tight text-ink">
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-ink text-surface" aria-hidden="true"><x-signal.ui.icon name="layers" class="h-5 w-5" /></span>{{ config('app.name') }}
                    </a>
                    <p class="mt-4 max-w-xs text-sm leading-6 text-muted">{{ __(config('marketing.summary')) }}</p>
                </div>
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Services') }}</p>
                    <nav class="mt-4 flex flex-col items-start gap-3" aria-label="{{ __('Footer navigation') }}">
                        @foreach ($services as $service)
                            <a href="{{ route('features', $service->key()) }}" class="text-sm font-semibold text-muted transition hover:text-ink">{{ $service->name() }}</a>
                        @endforeach
                    </nav>
                </div>
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Resources') }}</p>
                    <nav class="mt-4 flex flex-col items-start gap-3" aria-label="{{ __('Resources') }}">
                        <a href="{{ route('pricing') }}" class="text-sm font-semibold text-muted transition hover:text-ink">{{ __('Pricing') }}</a>
                        <a href="{{ route('docs.api') }}" class="text-sm font-semibold text-muted transition hover:text-ink">{{ __('API reference') }}</a>
                        <a href="{{ route('platform.status') }}" class="text-sm font-semibold text-muted transition hover:text-ink">{{ __('Status') }}</a>
                    </nav>
                </div>
            </div>
            <div class="border-t border-line">
                <div class="mx-auto flex max-w-6xl flex-col gap-2 px-5 py-5 text-xs text-subtle sm:flex-row sm:items-center sm:justify-between sm:px-8">
                    <span>© {{ now()->year }} {{ config('app.name') }}.</span>
                    <span>{{ __('Focused by design') }} <span aria-hidden="true">·</span> {{ __('Accessible by default') }}</span>
                </div>
            </div>
        </footer>
    </div>
</x-signal.layouts.base>
