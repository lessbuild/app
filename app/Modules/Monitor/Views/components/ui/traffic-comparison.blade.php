@props(['before', 'after'])
@php
    $rows = [
        'Pageviews' => [number_format($before->pageviews), number_format($after->pageviews)],
        'Visitors' => [number_format($before->visitors), number_format($after->visitors)],
        'Goal conversions' => [number_format($before->conversions), number_format($after->conversions)],
        'Converted visits' => [number_format($before->convertedVisits), number_format($after->convertedVisits)],
        'Batches accepted' => [number_format($before->acceptedBatches), number_format($after->acceptedBatches)],
        'Batches processed' => [number_format($before->processedBatches), number_format($after->processedBatches)],
        'Pending or processing submissions' => [number_format($before->unprocessedBatches), number_format($after->unprocessedBatches)],
        'Failed submissions' => [number_format($before->failedBatches), number_format($after->failedBatches)],
    ];
    $intakeSummary = static function ($window): string {
        if ($window->acceptedBatches === 0) {
            if ($window->pageviews === 0 && $window->visitors === 0 && $window->conversions === 0 && $window->convertedVisits === 0) {
                return 'No Analytics batches were accepted during this window. Zero observed event counts are not confirmation of zero site activity.';
            }

            return 'No Analytics batches were accepted during this intake-time window. Event-time counts may include historical events without batch records; intake counters do not establish tracker coverage.';
        }

        if ($window->processedBatches === $window->acceptedBatches) {
            return 'All '.$window->acceptedBatches.' batches accepted during this intake-time window have a processed status. This does not confirm tracker delivery or complete event-time coverage.';
        }

        return $window->processedBatches.' of '.$window->acceptedBatches.' batches accepted during this intake-time window have a processed status. Pending, failed, or unresolved batches may make event totals incomplete.';
    };
@endphp
<div class="overflow-x-auto">
    <x-monitor::ui.table caption="Analytics traffic and conversion comparison" :framed="false">
        <x-slot:head><tr><th scope="col">Metric</th><th scope="col">Before deployment</th><th scope="col">After deployment</th></tr></x-slot:head>
        @foreach($rows as $label => [$beforeValue, $afterValue])
            <tr><th scope="row" class="font-medium">{{ $label }}</th><td class="tabular-nums">{{ $beforeValue }}</td><td class="font-semibold tabular-nums">{{ $afterValue }}</td></tr>
        @endforeach
    </x-monitor::ui.table>
</div>
<p class="px-5 pt-4 text-xs leading-5 text-muted dark:text-subtle">Pageviews and conversions use event time. Accepted, processed, pending, and failed batch counts use intake time (when Analytics accepted each batch), so they do not map one-to-one to the event-time totals.</p>
<div class="grid gap-3 px-5 pt-3 text-xs leading-5 text-muted dark:text-subtle md:grid-cols-2">
    <p><span class="font-semibold text-foreground dark:text-foreground">Before-window intake:</span> {{ $intakeSummary($before) }}</p>
    <p><span class="font-semibold text-foreground dark:text-foreground">After-window intake:</span> {{ $intakeSummary($after) }}</p>
</div>
<p class="border-t border-line px-5 py-4 text-xs leading-5 text-muted dark:border-line dark:text-subtle">
    These are observed counts for the connected Analytics site. Ingestion delay, sampling, visitor mix, and overlapping releases affect the comparison; differences do not establish that this deployment caused a change.
    @if($after->latestBatchProcessedAt === null)
        Analytics has no successful batch completion time recorded for this site.
    @else
        Analytics' latest successful batch for this site completed at {{ $after->latestBatchProcessedAt->utc()->format('Y-m-d H:i:s') }} UTC; this site-wide activity time is not a coverage watermark.
    @endif
    Analytics cannot infer missed client-side events or tracker sampling from accepted batches.
</p>
@if($after->sourceUrl)
    <div class="border-t border-line px-5 py-4 dark:border-line"><a href="{{ $after->sourceUrl }}" class="text-xs font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus dark:text-primary">Open Analytics site →</a></div>
@endif
