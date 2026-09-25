@props(['before', 'after', 'expectedThrough'])
@php
    $rows = [
        'Pageviews' => [number_format($before->pageviews), number_format($after->pageviews)],
        'Visitors' => [number_format($before->visitors), number_format($after->visitors)],
        'Goal conversions' => [number_format($before->conversions), number_format($after->conversions)],
        'Converted visits' => [number_format($before->convertedVisits), number_format($after->convertedVisits)],
    ];
@endphp
<div class="overflow-x-auto">
    <x-monitor::ui.table caption="Analytics traffic and conversion comparison" :framed="false">
        <x-slot:head><tr><th scope="col">Metric</th><th scope="col">Before deployment</th><th scope="col">After deployment</th></tr></x-slot:head>
        @foreach($rows as $label => [$beforeValue, $afterValue])
            <tr><th scope="row" class="font-medium">{{ $label }}</th><td class="tabular-nums">{{ $beforeValue }}</td><td class="font-semibold tabular-nums">{{ $afterValue }}</td></tr>
        @endforeach
    </x-monitor::ui.table>
</div>
<p class="border-t border-line px-5 py-4 text-xs leading-5 text-muted dark:border-line dark:text-subtle">
    These are observed counts for the connected Analytics site. Ingestion delay, sampling, visitor mix, and overlapping releases affect the comparison; differences do not establish that this deployment caused a change.
    @if($after->processedAt === null)
        Analytics has no processed-through timestamp for this site; zero counts do not establish full-period coverage.
    @elseif($after->processedAt->lt($expectedThrough))
        Analytics processed through {{ $after->processedAt->utc()->format('Y-m-d H:i:s') }} UTC, before this comparison window ended; counts may be incomplete.
    @else
        Analytics processed through {{ $after->processedAt->utc()->format('Y-m-d H:i:s') }} UTC. This timestamp does not rule out ingestion gaps.
    @endif
</p>
@if($after->sourceUrl)
    <div class="border-t border-line px-5 py-4 dark:border-line"><a href="{{ $after->sourceUrl }}" class="text-xs font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus dark:text-primary">Open Analytics site →</a></div>
@endif
