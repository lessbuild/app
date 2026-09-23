<x-signal.layouts.core title="Buildpusher Analytics" product-key="analytics" :livewire="false">
    <main class="grid min-h-screen lg:grid-cols-[1.05fr_0.95fr]">
        <section class="hidden bg-emphasis p-10 text-emphasis-ink lg:flex lg:flex-col lg:justify-between">
            <a class="flex items-center gap-3 text-lg font-extrabold tracking-tight" href="{{ route('analytics.home') }}">
                <span class="grid size-10 place-items-center rounded-xl bg-surface text-ink"><img src="{{ asset('favicon.svg') }}" alt="" class="h-5 w-5"></span>
                Buildpusher
            </a>
            <div class="max-w-lg">
                <p class="mb-4 text-xs font-bold uppercase tracking-[0.2em] text-emphasis-muted">Analytics</p>
                <h1 class="text-5xl font-extrabold tracking-tight">Know what moves your website.</h1>
                <p class="mt-5 text-lg leading-8 text-emphasis-muted">A calm, privacy-aware view of visitors, pages, campaigns, and the actions that matter.</p>
            </div>
            <p class="text-xs text-emphasis-muted">Buildpusher Analytics</p>
        </section>
        <section class="flex items-center justify-center px-5 py-12 sm:px-8">
            <div class="w-full max-w-md">
                <div class="mb-10 flex items-center justify-between lg:hidden">
                    <a class="flex items-center gap-2 font-extrabold" href="{{ route('analytics.home') }}"><span class="grid size-9 place-items-center rounded-lg bg-ink text-xs text-surface"><img src="{{ asset('favicon.svg') }}" alt="" class="h-4 w-4"></span>Buildpusher</a>
                    <x-signal.ui.icon-button label="Use dark theme" data-theme-toggle aria-pressed="false">
                        <svg class="h-5 w-5" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#moon"></use></svg>
                    </x-signal.ui.icon-button>
                </div>
                @yield('content')
            </div>
        </section>
    </main>
</x-signal.layouts.core>
