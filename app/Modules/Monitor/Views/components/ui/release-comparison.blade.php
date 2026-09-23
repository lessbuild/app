@props(['left' => null, 'right' => null, 'leftLabel' => 'Baseline', 'rightLabel' => 'Selected release'])
@php
    $rows = [
        'Stored events' => [isset($left) ? number_format($left['events']) : '—', isset($right) ? number_format($right['events']) : '—'],
        'Request records' => [isset($left) ? number_format($left['requests']) : '—', isset($right) ? number_format($right['requests']) : '—'],
        'Error-flagged requests' => [isset($left) ? number_format($left['failed']) : '—', isset($right) ? number_format($right['failed']) : '—'],
        'Request error-signal ratio' => [isset($left['errorRate']) ? number_format($left['errorRate'], 2).'%' : 'No samples', isset($right['errorRate']) ? number_format($right['errorRate'], 2).'%' : 'No samples'],
        'Average timed request' => [isset($left['averageDuration']) ? number_format($left['averageDuration'], 2).' ms' : 'No timed samples', isset($right['averageDuration']) ? number_format($right['averageDuration'], 2).' ms' : 'No timed samples'],
        'Timed request samples' => [isset($left) ? number_format($left['timed']) : '—', isset($right) ? number_format($right['timed']) : '—'],
        'Exception records' => [isset($left) ? number_format($left['exceptions']) : '—', isset($right) ? number_format($right['exceptions']) : '—'],
        'Distinct linked issues' => [isset($left) ? number_format($left['issues']) : '—', isset($right) ? number_format($right['issues']) : '—'],
    ];
@endphp
<div class="overflow-x-auto">
    <x-monitor::ui.table caption="Release comparison" :framed="false">
        <x-slot:head><tr><th scope="col">Metric</th><th scope="col" class="max-w-xs break-words">{{ $leftLabel }}</th><th scope="col" class="max-w-xs break-words">{{ $rightLabel }}</th></tr></x-slot:head>
@foreach($rows as $label => [$before, $after])<tr><th scope="row" class="font-medium">{{ $label }}</th><td class="tabular-nums">{{ $before }}</td><td class="font-semibold tabular-nums">{{ $after }}</td></tr>@endforeach
    </x-monitor::ui.table>
</div>
@if(isset($left['errorRate'], $right['errorRate']) || isset($left['averageDuration'], $right['averageDuration']))
    <p class="border-t border-line px-5 py-4 text-xs leading-6 dark:border-line">Observed change:
        @if(isset($left['errorRate'], $right['errorRate'])) error-signal ratio {{ sprintf('%+.2f', $right['errorRate'] - $left['errorRate']) }} percentage points. @endif
        @if(isset($left['averageDuration'], $right['averageDuration'])) average duration {{ sprintf('%+.2f', $right['averageDuration'] - $left['averageDuration']) }} ms. @endif
    </p>
@endif
<p class="border-t border-line px-5 py-4 text-xs leading-5 text-muted dark:border-line dark:text-subtle">Only retained, explicitly versioned telemetry from unarchived environments is included. A request is error-flagged by HTTP 5xx or error/critical severity. Request records may include internal and outbound spans; these are not unique user requests or availability measurements. Sampling, traffic mix, missing data and source-clock skew affect comparisons. Differences do not establish deployment causality or statistical significance.</p>
