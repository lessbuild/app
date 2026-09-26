@props(['projectName' => null, 'headingId' => 'product-suite-preview-heading'])

@php($projectName = $projectName ?: __('Storefront'))

<x-signal.ui.panel
    as="article"
    {{ $attributes->class(['product-preview-window min-w-0 rounded-panel p-3 shadow-panel sm:p-4']) }}
    aria-labelledby="{{ $headingId }}"
>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-2 pb-3 sm:px-3">
        <div class="flex min-w-0 items-center gap-2.5">
            <span class="grid size-8 place-items-center rounded-lg bg-product-navy text-white">
                <x-signal.ui.icon name="layers" class="size-4" />
            </span>
            <div class="min-w-0">
                <h2 id="{{ $headingId }}" class="truncate text-sm font-extrabold text-ink">{{ $projectName }}</h2>
                <p class="text-[10px] text-muted">{{ __('Illustrative project overview') }}</p>
            </div>
        </div>
        <x-signal.ui.badge tone="neutral">{{ __('Shared project') }}</x-signal.ui.badge>
    </div>

    <section class="mt-3 rounded-card border border-line bg-surface p-3 sm:p-4" aria-labelledby="{{ $headingId }}-release-heading">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 id="{{ $headingId }}-release-heading" class="text-[10px] font-bold text-muted">{{ __('Latest release') }}</h3>
                <p class="mt-1 flex items-center gap-2 text-xs font-extrabold text-ink">
                    <x-signal.ui.status-dot aria-hidden="true" />
                    {{ __('Deployed') }}
                </p>
            </div>
            <x-signal.ui.badge tone="success">{{ __('Health verified') }}</x-signal.ui.badge>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-line pt-3 font-mono text-[10px] text-muted">
            <span aria-hidden="true">⑂</span>
            <strong class="text-ink">main</strong>
            <code class="product-code">a1b2c3d</code>
            <span class="min-w-0 flex-1">{{ __('Update storefront') }}</span>
            <span>v2.18.4</span>
        </div>
    </section>

    <div class="mt-3 grid min-w-0 gap-3 sm:grid-cols-2">
        <section class="min-w-0 rounded-card border border-line bg-surface p-3 sm:p-4" aria-labelledby="{{ $headingId }}-health-heading">
            <div class="flex items-center justify-between gap-2">
                <h3 id="{{ $headingId }}-health-heading" class="text-[10px] font-bold text-ink">{{ __('Service health') }}</h3>
                <span class="flex items-center gap-1.5 text-[9px] font-bold text-success">
                    <x-signal.ui.status-dot aria-hidden="true" />
                    {{ __('Healthy') }}
                </span>
            </div>
            <div class="mt-4 grid gap-3">
                @foreach ([['name' => __('Web'), 'value' => '99.9%'], ['name' => __('API'), 'value' => '99.8%']] as $service)
                    <div class="grid min-w-0 grid-cols-[2.2rem_1fr_2.5rem] items-center gap-2">
                        <span class="text-[9px] font-bold text-muted">{{ $service['name'] }}</span>
                        <span class="product-uptime-bars" role="img" aria-label="{{ __('Illustrative healthy service checks') }}">
                            @for ($interval = 0; $interval < 12; $interval++)
                                <i></i>
                            @endfor
                        </span>
                        <span class="text-right text-[9px] text-muted">{{ $service['value'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="min-w-0 rounded-card border border-line bg-surface p-3 sm:p-4" aria-labelledby="{{ $headingId }}-traffic-heading">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h3 id="{{ $headingId }}-traffic-heading" class="text-[10px] font-bold text-muted">{{ __('Traffic estimates') }}</h3>
                    <p class="mt-1 text-xl font-extrabold tracking-tight text-ink">1,248</p>
                </div>
                <x-signal.ui.badge tone="success">{{ __('↑ 12%') }}</x-signal.ui.badge>
            </div>
            <svg class="product-sparkline mt-1 h-12 w-full" viewBox="0 0 240 58" role="img" aria-label="{{ __('Illustrative traffic trend using sample data') }}">
                <path class="product-sparkline-fill" d="M0 49 18 39 34 44 52 35 70 40 87 31 102 34 119 21 136 28 151 19 169 20 185 10 201 20 218 12 232 2 240 4V58H0Z" />
                <path class="product-sparkline-line" d="M0 49 18 39 34 44 52 35 70 40 87 31 102 34 119 21 136 28 151 19 169 20 185 10 201 20 218 12 232 2 240 4" />
            </svg>
            <div class="mt-1 flex justify-between text-[8px] text-subtle">
                <span>{{ __('Mar 1') }}</span><span>{{ __('Mar 15') }}</span><span>{{ __('Mar 31') }}</span>
            </div>
        </section>
    </div>

    <p class="mt-3 text-right text-[9px] text-subtle">{{ __('Illustrative interface. Values and activity are fictional sample data.') }}</p>
</x-signal.ui.panel>
