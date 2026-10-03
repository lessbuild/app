@props([
    'label',
    'points',
    'unit' => '',
    'markers' => [],
])

{{--
    A single-series column chart: points is a list of ['label' => string, 'value' => int].
    Columns are the chart hue, at most 24px wide with a 4px rounded top and a 2px gap; each is focusable
    with a hover/focus readout, and the same numbers are in a table under "Show the numbers".
    markers (optional) is a list of ['label' => a point's label, 'text' => string]: each draws a dashed line with a
    small flag above that column, such as a release going live, and is named in the column's readout.
--}}
@php
    $markedLabels = [];
    foreach ($markers as $marker) {
        $markedLabels[$marker['label']][] = $marker['text'];
    }
    $max = max(1, ...array_map(fn (array $point): int => (int) $point['value'], $points ?: [['value' => 0]]));
    $step = 10 ** max(0, (int) floor(log10($max)));
    $top = (int) (ceil($max / $step) * $step);
@endphp

<figure {{ $attributes->class(['grid gap-3 pt-8']) }}>
    <div class="grid grid-cols-[auto_minmax(0,1fr)] gap-2">
        <div class="flex h-48 flex-col justify-between text-right text-[11px] tabular-nums text-muted" aria-hidden="true">
            <span>{{ number_format($top) }}</span>
            <span>0</span>
        </div>
        <div class="relative flex h-48 items-end gap-[2px] border-b border-l border-line" role="list" aria-label="{{ $label }}">
            @foreach ($points as $point)
                {{-- Readouts near either edge anchor inward so they never leave the chart. --}}
                @php($third = $loop->index / max(1, $loop->count - 1))
                @php($marked = $markedLabels[$point['label']] ?? [])
                <div class="group relative flex h-full flex-1 items-end justify-center outline-none" role="listitem" tabindex="0" aria-label="{{ $point['label'] }}: {{ number_format($point['value']) }} {{ $unit }}{{ $marked !== [] ? ' · '.implode(', ', $marked) : '' }}">
                    @if ($marked !== [])
                        <span class="pointer-events-none absolute inset-y-0 left-1/2 border-l-2 border-dashed border-muted/60" aria-hidden="true"></span>
                        <span class="pointer-events-none absolute -top-5 left-1/2 -translate-x-1/2 rounded-full bg-ink px-1.5 text-[10px] font-bold leading-4 text-surface" aria-hidden="true">{{ count($marked) > 1 ? count($marked) : '▲' }}</span>
                    @endif
                    <div class="w-full max-w-6 rounded-t bg-chart-1 transition-opacity group-hover:opacity-80 group-focus-visible:outline-2 group-focus-visible:outline-offset-2 group-focus-visible:outline-focus" style="height: {{ $point['value'] > 0 ? max(1.5, $point['value'] / $top * 100) : 0 }}%"></div>
                    <div @class(['pointer-events-none absolute bottom-full z-10 mb-1 hidden whitespace-nowrap rounded-control border border-line bg-surface px-2 py-1 text-xs shadow-panel group-hover:block group-focus-visible:block', 'left-0' => $third < 0.25, 'right-0' => $third > 0.75, 'left-1/2 -translate-x-1/2' => $third >= 0.25 && $third <= 0.75])>
                        <strong class="text-ink">{{ number_format($point['value']) }}</strong> <span class="text-muted">{{ $unit }} · {{ $point['label'] }}</span>
                        @foreach ($marked as $text)<span class="block text-muted">{{ $text }}</span>@endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    <div class="flex justify-between pl-8 text-[11px] text-muted" aria-hidden="true">
        <span>{{ $points[0]['label'] ?? '' }}</span>
        <span>{{ $points[intdiv(count($points), 2)]['label'] ?? '' }}</span>
        <span>{{ $points[count($points) - 1]['label'] ?? '' }}</span>
    </div>
    <details class="text-xs">
        <summary class="cursor-pointer font-bold text-muted">{{ __('Show the numbers') }}</summary>
        <x-signal.ui.table :caption="$label" class="mt-2 max-h-72 overflow-y-auto">
            <x-slot:head><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ ucfirst($unit) }}</th></tr></x-slot:head>
            @foreach ($points as $point)
                <tr><td>{{ $point['label'] }}</td><td class="tabular-nums">{{ number_format($point['value']) }}</td></tr>
            @endforeach
        </x-signal.ui.table>
    </details>
</figure>
