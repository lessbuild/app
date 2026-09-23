<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Data\Telemetry\TraceRecord;
use App\Modules\Monitor\Models\TelemetryEvent;
use Illuminate\Support\Collection;

final class TraceTimeline
{
    /**
     * @param  Collection<int, TelemetryEvent>  $events
     * @return array{
     *   rows: list<array{record: TraceRecord, offset: float, left: float, width: float, depth: int, notes: list<string>}>,
     *   duration: float, spanCount: int, eventCount: int, errorCount: int, warningCount: int, missingDurationCount: int,
     *   services: Collection<string, Collection<int, TraceRecord>>, serviceCount: int, first: TraceRecord, title: string
     * }
     */
    public function build(Collection $events): array
    {
        $records = $events->map(fn (TelemetryEvent $event): TraceRecord => new TraceRecord($event))
            ->sort(fn (TraceRecord $left, TraceRecord $right): int => [$left->seconds, $left->nanoseconds, $left->event->id]
                <=> [$right->seconds, $right->nanoseconds, $right->event->id])
            ->keyBy(fn (TraceRecord $record): int => $record->event->id);
        $first = $records->first();
        $duration = (float) $records->max(fn (TraceRecord $record): float => $record->millisecondsSince($first) + ($record->durationMs ?? 0));
        $spans = $records->filter(fn (TraceRecord $record): bool => $record->isSpan);
        $spanIds = $spans->groupBy(fn (TraceRecord $record): string => $record->event->span_id);
        $parents = [];
        $notes = [];

        foreach ($records as $id => $record) {
            $parents[$id] = null;
            $notes[$id] = [];
            if ($record->isSpan && $spanIds->get($record->event->span_id)->count() > 1) {
                $notes[$id][] = 'Duplicate span ID';
            }

            $parentSpanId = $record->isSpan ? $record->event->parent_span_id : $record->event->span_id;
            if (blank($parentSpanId)) {
                continue;
            }

            $candidates = $spanIds->get($parentSpanId, collect());
            if ($candidates->count() === 1) {
                $parents[$id] = $candidates->first()->event->id;
            } else {
                $notes[$id][] = $candidates->isEmpty() ? 'Parent span not in this view' : 'Ambiguous parent span';
            }
        }

        $this->breakCycles($parents, $notes);
        $children = [];
        foreach ($parents as $id => $parent) {
            $children[$parent ?? 0][] = $id;
        }

        $stack = array_map(fn (int $id): array => [$id, 0], array_reverse($children[0] ?? []));
        $rows = [];
        while ($stack !== []) {
            [$id, $depth] = array_pop($stack);
            $record = $records->get($id);
            $offset = $record->millisecondsSince($first);
            $left = $duration > 0 ? min(100.0, max(0.0, $offset / $duration * 100)) : 0.0;
            $width = $duration > 0 ? min(100 - $left, ($record->durationMs ?? 0) / $duration * 100) : 0.0;
            $rows[] = [
                'record' => $record,
                'offset' => $offset,
                'left' => $left,
                'width' => $width,
                'depth' => $depth,
                'notes' => $notes[$id],
            ];
            foreach (array_reverse($children[$id] ?? []) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }

        $root = $spans->first(fn (TraceRecord $record): bool => blank($record->event->parent_span_id));

        return [
            'rows' => $rows,
            'duration' => round($duration, 6),
            'spanCount' => $spans->count(),
            'eventCount' => $records->count() - $spans->count(),
            'errorCount' => $records->filter(fn (TraceRecord $record): bool => $record->hasError)->count(),
            'warningCount' => $records->filter(fn (TraceRecord $record): bool => $record->hasWarning && ! $record->hasError)->count(),
            'missingDurationCount' => $records->filter(fn (TraceRecord $record): bool => $record->durationMs === null)->count(),
            'services' => $records->groupBy(fn (TraceRecord $record): string => $record->service()),
            'serviceCount' => $records->map(fn (TraceRecord $record): ?string => $record->event->service)->filter(fn (?string $service): bool => filled($service))->uniqueStrict()->count(),
            'first' => $first,
            'title' => $root?->name() ?? 'Trace investigation',
        ];
    }

    /**
     * @param  array<int, int|null>  $parents
     * @param  array<int, list<string>>  $notes
     */
    private function breakCycles(array &$parents, array &$notes): void
    {
        $visited = [];
        foreach (array_keys($parents) as $id) {
            $path = [];
            $current = $id;
            while ($current !== null && ! isset($visited[$current])) {
                if (isset($path[$current])) {
                    $cycle = array_slice(array_keys($path), $path[$current]);
                    foreach ($cycle as $member) {
                        $parents[$member] = null;
                        $notes[$member][] = 'Cyclic parent link';
                    }

                    break;
                }
                $path[$current] = count($path);
                $current = $parents[$current];
            }
            foreach (array_keys($path) as $member) {
                $visited[$member] = true;
            }
        }
    }
}
