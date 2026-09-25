<x-signal.layouts.core
    :title="__('Buildpusher status')"
    :description="__('Current operational status for Buildpusher platform services.')"
    :canonical="route('core.status')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-screen-2xl px-5 py-12 sm:px-8 sm:py-16">
        <x-signal.ui.page-header
            eyebrow="{{ __('Service status') }}"
            :title="__('Buildpusher status')"
            :description="__('Current operational status for Buildpusher and its enabled apps.')"
        >
            <x-slot:actions>
                <x-signal.ui.button :href="route('core.status.report')" variant="secondary">{{ __('JSON report') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('core.entry')" variant="quiet">{{ __('Buildpusher home') }}</x-signal.ui.button>
            </x-slot:actions>
        </x-signal.ui.page-header>

        <x-signal.ui.card class="mt-8 p-5 sm:p-7">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ __('Overall status') }}</p>
                    <h2 class="mt-2 text-2xl font-extrabold text-ink">{{ $snapshot['operational'] ? __('All systems operational') : __('Some systems are degraded') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Last checked :time UTC', ['time' => \Illuminate\Support\Carbon::parse($snapshot['checked_at'])->format('Y-m-d H:i:s')]) }}</p>
                </div>
                <x-signal.ui.badge :tone="$snapshot['operational'] ? 'success' : 'warning'">
                    {{ $snapshot['operational'] ? __('Operational') : __('Degraded') }}
                </x-signal.ui.badge>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($snapshot['components'] as $component)
                    <article class="rounded-panel border border-line bg-surface-muted/50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-bold text-ink">{{ $component['name'] }}</h3>
                            <x-signal.ui.badge :tone="$component['operational'] ? 'success' : 'warning'">{{ $component['status'] }}</x-signal.ui.badge>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-muted">{{ $component['description'] }}</p>
                    </article>
                @endforeach
            </div>
        </x-signal.ui.card>

        <p class="mt-4 text-xs leading-5 text-muted">{{ __('Availability checks are supplied independently by each enabled app. Customer status pages and operational records remain in their product-owned databases.') }}</p>
    </main>
</x-signal.layouts.core>
