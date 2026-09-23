@props([
    'summary',
    'productLabel',
    'compact' => false,
])

@php
    [$summaryTone, $summaryLabel] = match ($summary->state) {
        \App\Core\Data\Projects\ProjectProductSnapshotState::Current => ['success', __('Current')],
        \App\Core\Data\Projects\ProjectProductSnapshotState::Attention => ['warning', __('Needs attention')],
        \App\Core\Data\Projects\ProjectProductSnapshotState::Empty => ['neutral', __('No data yet')],
        \App\Core\Data\Projects\ProjectProductSnapshotState::Unavailable => ['neutral', __('Unavailable')],
    };
@endphp

<x-signal.ui.card @class([
    'p-4 shadow-none' => $compact,
    'p-5' => ! $compact,
])>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="ui-eyebrow">{{ $productLabel }}</p>
            @if ($compact)
                <h4 class="mt-2 text-base font-extrabold text-ink">{{ $summary->title }}</h4>
            @else
                <h2 class="mt-2 text-base font-extrabold text-ink">{{ $summary->title }}</h2>
            @endif
        </div>
        <x-signal.ui.badge :tone="$summaryTone">{{ $summaryLabel }}</x-signal.ui.badge>
    </div>
    <p @class([
        'mt-3 text-sm leading-6 text-muted' => $compact,
        'mt-4 text-sm leading-6 text-muted' => ! $compact,
    ])>{{ $summary->detail }}</p>
    @if ($summary->updatedAt)
        <p class="mt-3 text-xs text-subtle">
            {{ __('Updated :time', ['time' => $summary->updatedAt->diffForHumans()]) }}
            <time class="sr-only" datetime="{{ $summary->updatedAt->toIso8601String() }}">{{ $summary->updatedAt->toIso8601String() }}</time>
        </p>
    @elseif ($summary->state === \App\Core\Data\Projects\ProjectProductSnapshotState::Unavailable)
        <p class="mt-3 text-xs text-subtle">{{ __('Freshness is unavailable while app data cannot be reached.') }}</p>
    @else
        <p class="mt-3 text-xs text-subtle">{{ __('No recent activity to report.') }}</p>
    @endif
    @if ($summary->url)
        <x-signal.ui.link :href="$summary->url" size="sm" class="mt-4">
            {{ __('Open :product', ['product' => $productLabel]) }}
            <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-up-right"></use></svg>
        </x-signal.ui.link>
    @endif
</x-signal.ui.card>
