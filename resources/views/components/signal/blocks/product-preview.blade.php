@props(['productKey', 'product', 'headingId' => 'product-preview-heading'])

@php
    $accent = in_array($product['accent'] ?? null, ['deploy', 'monitor', 'analytics'], true) ? $product['accent'] : 'deploy';
    $preview = $product['preview'];
    $icon = in_array($product['icon'] ?? null, ['layers', 'pulse', 'chart'], true) ? $product['icon'] : 'layers';
@endphp

<x-signal.ui.panel as="article" {{ $attributes->class(['product-detail-preview', 'product-preview-'.$accent, 'min-w-0', 'rounded-panel', 'p-4', 'shadow-panel', 'sm:p-5']) }} aria-labelledby="{{ $headingId }}">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
        <div class="flex min-w-0 items-center gap-3">
            <span class="product-icon-{{ $accent }} grid size-10 shrink-0 place-items-center rounded-xl">
                <x-signal.ui.icon :name="$icon" class="size-5" />
            </span>
            <div class="min-w-0">
                <h2 id="{{ $headingId }}" class="truncate text-sm font-extrabold text-ink">{{ $preview['title'] }}</h2>
                <p class="mt-1 truncate text-xs text-muted">{{ $preview['context'] }}</p>
            </div>
        </div>
        <x-signal.ui.badge :tone="$preview['status_tone']">{{ $preview['status'] }}</x-signal.ui.badge>
    </div>

    <p class="mt-4 text-sm leading-6 text-muted">{{ $preview['description'] }}</p>

    @if ($productKey === 'deployer')
        <ol class="mt-4 grid grid-cols-3 gap-2" aria-label="{{ __('Illustrative release stages') }}">
            @foreach ([__('Preflight'), __('Release'), __('Verify')] as $stage)
                <li class="rounded-control border border-line bg-surface-muted p-3">
                    <span class="grid size-6 place-items-center rounded-full bg-success text-white"><x-signal.ui.icon name="check" class="size-3.5" /></span>
                    <p class="mt-2 text-xs font-extrabold text-ink">{{ $stage }}</p>
                    <p class="mt-1 text-[0.65rem] text-muted">{{ __('Recorded') }}</p>
                </li>
            @endforeach
        </ol>
    @elseif ($productKey === 'monitor')
        <div class="mt-4 rounded-control border border-line bg-surface-muted p-3 sm:p-4">
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-extrabold text-ink">{{ __('Scheduled check history') }}</p>
                <span class="text-[0.65rem] text-muted">{{ __('Illustrative intervals') }}</span>
            </div>
            <div class="product-uptime-bars product-uptime-bars-wide mt-3" role="img" aria-label="{{ __('Illustrative service check history using sample data') }}">
                @for ($interval = 0; $interval < 24; $interval++)
                    <i></i>
                @endfor
            </div>
        </div>
    @elseif ($productKey === 'analytics')
        <div class="mt-4 rounded-control border border-line bg-surface-muted p-3 sm:p-4" data-product-analytics>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-extrabold text-ink">{{ __('Traffic estimates') }}</p>
                    <p class="mt-1 text-[0.65rem] text-muted">{{ __('Storefront') }} · <span data-product-analytics-period>{{ __('Last 30 days') }}</span></p>
                    <p class="mt-2 text-2xl font-extrabold tracking-tight text-ink" data-product-visitors>1,248</p>
                    <p class="text-[0.65rem] text-muted">{{ __('Estimated visitors') }} <span class="ml-1 font-bold text-success" data-product-change>↑ 12%</span></p>
                </div>
                <label class="w-32">
                    <span class="sr-only">{{ __('Analytics sample date range') }}</span>
                    <x-signal.ui.select
                        :id="'analytics-preview-range-'.$headingId"
                        name="analytics_preview_range"
                        class="!min-h-9 !py-1.5 !text-[10px]"
                        data-product-analytics-range
                        disabled
                    >
                        <option
                            value="7"
                            data-visitors="312"
                            data-change="↑ 8%"
                            data-label="{{ __('Last 7 days') }}"
                            data-summary="{{ __('Illustrative estimate for the last 7 days.') }}"
                            data-announcement="{{ __('Showing illustrative traffic for the last 7 days.') }}"
                            data-chart-label="{{ __('Illustrative traffic estimate trend for the last 7 days') }}"
                            data-chart-path="M0 82 45 65 90 72 135 48 180 55 225 38 270 45 315 24 360 33 405 19 450 26 500 7"
                        >{{ __('Last 7 days') }}</option>
                        <option
                            value="30"
                            data-visitors="1,248"
                            data-change="↑ 12%"
                            data-label="{{ __('Last 30 days') }}"
                            data-summary="{{ __('Illustrative estimate for the last 30 days.') }}"
                            data-announcement="{{ __('Showing illustrative traffic for the last 30 days.') }}"
                            data-chart-label="{{ __('Illustrative traffic estimate trend for the last 30 days') }}"
                            data-chart-path="M0 91 24 71 49 78 73 61 98 69 123 51 147 57 172 42 196 52 221 34 246 44 270 27 295 38 319 24 344 32 369 15 393 24 418 10 443 18 468 3 500 5"
                            selected
                        >{{ __('Last 30 days') }}</option>
                        <option
                            value="90"
                            data-visitors="3,842"
                            data-change="↑ 16%"
                            data-label="{{ __('Last 90 days') }}"
                            data-summary="{{ __('Illustrative estimate for the last 90 days.') }}"
                            data-announcement="{{ __('Showing illustrative traffic for the last 90 days.') }}"
                            data-chart-label="{{ __('Illustrative traffic estimate trend for the last 90 days') }}"
                            data-chart-path="M0 88 24 79 49 68 73 76 98 56 123 62 147 46 172 51 196 35 221 42 246 28 270 39 295 20 319 31 344 18 369 24 393 11 418 18 443 7 468 12 500 2"
                        >{{ __('Last 90 days') }}</option>
                    </x-signal.ui.select>
                </label>
            </div>
            <p class="mt-1 text-[0.6rem] text-subtle" data-product-analytics-summary>{{ __('Illustrative estimate for the last 30 days.') }}</p>
            <p class="sr-only" data-product-analytics-status role="status" aria-live="polite">{{ __('Showing illustrative traffic for the last 30 days.') }}</p>
            <svg class="product-sparkline mt-3 h-24 w-full" viewBox="0 0 500 112" role="img" aria-label="{{ __('Illustrative traffic estimate trend for the last 30 days') }}" data-product-traffic-chart>
                <path class="product-sparkline-fill" d="M0 91 24 71 49 78 73 61 98 69 123 51 147 57 172 42 196 52 221 34 246 44 270 27 295 38 319 24 344 32 369 15 393 24 418 10 443 18 468 3 500 5V112H0Z" data-product-traffic-fill />
                <path class="product-sparkline-line" d="M0 91 24 71 49 78 73 61 98 69 123 51 147 57 172 42 196 52 221 34 246 44 270 27 295 38 319 24 344 32 369 15 393 24 418 10 443 18 468 3 500 5" data-product-traffic-line />
            </svg>
            <div class="mt-1 flex justify-between text-[0.6rem] text-subtle">
                <span>{{ __('30 days ago') }}</span><span>{{ __('15 days ago') }}</span><span>{{ __('Today') }}</span>
            </div>
        </div>
    @endif

    <div class="mt-4 grid min-w-0 grid-cols-3 gap-2">
        @foreach ($preview['metrics'] as $metric)
            <x-signal.ui.card as="dl" class="min-w-0 p-3 shadow-none">
                <dt class="truncate text-[0.65rem] font-semibold text-muted">{{ $metric['label'] }}</dt>
                <dd class="mt-1 truncate text-sm font-extrabold text-ink">{{ $metric['value'] }}</dd>
            </x-signal.ui.card>
        @endforeach
    </div>

    <div class="mt-5">
        <h3 class="text-xs font-extrabold text-ink">{{ $preview['activity_label'] }}</h3>
        <ul class="mt-2 divide-y divide-line rounded-control border border-line bg-surface px-3">
            @foreach ($preview['activity'] as $event)
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-ink">{{ $event['title'] }}</p>
                        <p class="mt-1 text-[0.65rem] leading-5 text-muted">{{ $event['detail'] }}</p>
                    </div>
                    <span class="shrink-0 text-[0.65rem] font-bold text-muted">{{ $event['state'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <p class="mt-3 text-right text-[0.65rem] text-subtle">{{ __('Illustrative interface. Values and activity are fictional sample data.') }}</p>
</x-signal.ui.panel>
