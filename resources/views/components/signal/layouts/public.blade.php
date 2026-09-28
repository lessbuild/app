@props([
    'title',
    'description' => null,
    'canonical' => null,
])

{{-- The public site: a simple header with the services, pricing and docs, and a footer. Indexed by search engines. --}}
<x-signal.layouts.base :title="$title" :description="$description" indexable :canonical="$canonical">
    <header class="border-b border-line bg-surface">
        <nav class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-8" aria-label="{{ __('Site') }}">
            <a href="{{ route('home') }}" class="text-lg font-extrabold text-ink">{{ config('app.name') }}</a>
            <div class="flex flex-wrap items-center gap-4 text-sm font-bold">
                @foreach (app(\App\Platform\ServiceRegistry::class)->all() as $service)
                    <a href="{{ route('features', $service->key()) }}" class="text-muted hover:text-ink" @if (request()->routeIs('features') && request()->route('service') === $service->key()) aria-current="page" @endif>{{ $service->name() }}</a>
                @endforeach
                <a href="{{ route('pricing') }}" class="text-muted hover:text-ink" @if (request()->routeIs('pricing')) aria-current="page" @endif>{{ __('Pricing') }}</a>
                <a href="{{ route('docs.api') }}" class="text-muted hover:text-ink">{{ __('API') }}</a>
                <a href="{{ route('login') }}" class="text-muted hover:text-ink">{{ __('Sign in') }}</a>
                <x-signal.ui.button :href="route('register')" variant="primary" size="sm">{{ __('Get started') }}</x-signal.ui.button>
            </div>
        </nav>
    </header>
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-6xl gap-12 px-4 py-12 sm:px-8 sm:py-16">
        {{ $slot }}
    </main>
    <footer class="border-t border-line">
        <div class="mx-auto flex max-w-6xl flex-wrap gap-4 px-4 py-6 text-xs text-muted sm:px-8">
            <span>© {{ now()->year }} {{ config('app.name') }}</span>
            <a href="{{ route('platform.status') }}" class="hover:text-ink">{{ __('Status') }}</a>
            <a href="{{ route('docs.api') }}" class="hover:text-ink">{{ __('API reference') }}</a>
            <a href="{{ route('pricing') }}" class="hover:text-ink">{{ __('Pricing') }}</a>
        </div>
    </footer>
</x-signal.layouts.base>
