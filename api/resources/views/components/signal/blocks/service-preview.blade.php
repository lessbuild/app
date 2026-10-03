@props(['copy', 'headingId' => 'service-preview-heading'])

@php($accent = $copy['accent'])
@php($preview = $copy['preview'])

{{-- An illustrative glimpse of a service's dashboard, as on the Signal product pages. Every value is sample data. --}}
<x-signal.ui.panel as="article" {{ $attributes->class(['product-detail-preview', 'product-preview-'.$accent, 'min-w-0', 'rounded-panel', 'p-4', 'sm:p-5']) }} aria-labelledby="{{ $headingId }}">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
        <div class="flex min-w-0 items-center gap-3">
            <span class="product-icon-{{ $accent }} grid size-10 shrink-0 place-items-center rounded-xl" aria-hidden="true"><x-signal.ui.icon :name="$copy['icon']" class="size-5" /></span>
            <div class="min-w-0">
                <h2 id="{{ $headingId }}" class="truncate text-sm font-extrabold text-ink">{{ __($preview['title']) }}</h2>
                <p class="mt-1 truncate text-xs text-muted">{{ __($preview['context']) }}</p>
            </div>
        </div>
        <x-signal.ui.badge :tone="$preview['status_tone']">{{ __($preview['status']) }}</x-signal.ui.badge>
    </div>

    <p class="mt-4 text-sm leading-6 text-muted">{{ __($preview['description']) }}</p>

    @switch($accent)
        @case('deploy')
            <ol class="mt-4 grid grid-cols-3 gap-2" aria-label="{{ __('Illustrative release stages') }}">
                @foreach (['Approve', 'Release', 'Verify'] as $stage)
                    <li class="rounded-control border border-line bg-surface-muted p-3">
                        <span class="grid size-6 place-items-center rounded-full bg-success text-white" aria-hidden="true"><x-signal.ui.icon name="check" class="size-3.5" /></span>
                        <p class="mt-2 text-xs font-extrabold text-ink">{{ __($stage) }}</p>
                        <p class="mt-1 text-[0.65rem] text-muted">{{ __('Recorded') }}</p>
                    </li>
                @endforeach
            </ol>
            @break
        @case('infrastructure')
            <div class="mt-4 grid gap-3 rounded-control border border-line bg-surface-muted p-3 sm:p-4">
                @foreach ([['CPU', 18], ['Memory', 46], ['Disk', 31]] as [$label, $value])
                    <div>
                        <div class="flex justify-between text-[0.65rem] font-bold"><span class="text-muted">{{ __($label) }}</span><span class="text-ink">{{ $value }}%</span></div>
                        <x-signal.ui.progress :value="$value" :label="__($label)" role="meter" class="mt-1.5" />
                    </div>
                @endforeach
            </div>
            @break
        @case('monitor')
            <div class="mt-4 rounded-control border border-line bg-surface-muted p-3 sm:p-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-extrabold text-ink">{{ __('Check history') }}</p>
                    <span class="text-[0.65rem] text-muted">{{ __('Last 24 hours') }}</span>
                </div>
                <div class="product-uptime-bars product-uptime-bars-wide mt-3" role="img" aria-label="{{ __('Illustrative check history using sample data') }}">
                    @for ($hour = 0; $hour < 24; $hour++)
                        <i></i>
                    @endfor
                </div>
            </div>
            @break
        @case('analytics')
            <div class="mt-4 rounded-control border border-line bg-surface-muted p-3 sm:p-4">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <p class="text-xs font-extrabold text-ink">{{ __('Visitors') }}</p>
                        <p class="mt-2 text-2xl font-extrabold tracking-tight text-ink">1,248</p>
                    </div>
                    <span class="text-xs font-bold text-success">↑ 12%</span>
                </div>
                <svg class="product-sparkline mt-3 h-20 w-full" viewBox="0 0 500 112" preserveAspectRatio="none" role="img" aria-label="{{ __('Illustrative visitor trend using sample data') }}">
                    <path class="product-sparkline-fill" d="M0 91 24 71 49 78 73 61 98 69 123 51 147 57 172 42 196 52 221 34 246 44 270 27 295 38 319 24 344 32 369 15 393 24 418 10 443 18 468 3 500 5V112H0Z" />
                    <path class="product-sparkline-line" d="M0 91 24 71 49 78 73 61 98 69 123 51 147 57 172 42 196 52 221 34 246 44 270 27 295 38 319 24 344 32 369 15 393 24 418 10 443 18 468 3 500 5" />
                </svg>
            </div>
            @break
    @endswitch

    <dl class="mt-4 grid min-w-0 grid-cols-3 gap-2">
        @foreach ($preview['metrics'] as [$label, $value])
            <div class="ui-card min-w-0 p-3 shadow-none">
                <dt class="truncate text-[0.65rem] font-semibold text-muted">{{ __($label) }}</dt>
                <dd class="mt-1 truncate text-sm font-extrabold text-ink">{{ __($value) }}</dd>
            </div>
        @endforeach
    </dl>

    {{-- The activity list is detail; phones get the summary above. --}}
    <div class="mt-5 hidden sm:block">
        <h3 class="text-xs font-extrabold text-ink">{{ __($preview['activity_label']) }}</h3>
        <ul class="mt-2 divide-y divide-line rounded-control border border-line bg-surface px-3">
            @foreach ($preview['activity'] as [$title, $detail, $state])
                <li class="flex items-center gap-3 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-ink">{{ __($title) }}</p>
                        <p class="mt-1 text-[0.65rem] leading-5 text-muted">{{ __($detail) }}</p>
                    </div>
                    <span class="shrink-0 text-[0.65rem] font-bold text-muted">{{ __($state) }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <p class="mt-3 text-right text-[0.65rem] text-subtle">{{ __('Illustrative interface with sample data.') }}</p>
</x-signal.ui.panel>
