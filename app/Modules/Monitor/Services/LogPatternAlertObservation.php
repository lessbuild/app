<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class LogPatternAlertObservation
{
    /** @return array{state: string, value: float|null, samples: int, reason: string|null} */
    public function measure(AlertRule $rule, CarbonImmutable $from, CarbonImmutable $until): array
    {
        if ($rule->match_text === null || $rule->match_text === '') {
            return ['state' => 'no_data', 'value' => null, 'samples' => 0, 'reason' => 'pattern_unavailable'];
        }

        $query = TelemetryEvent::query()->where('environment_id', $rule->environment_id)
            ->when($rule->service !== null, fn (Builder $events): Builder => $events->where('service', $rule->service))
            ->where('occurred_at', '>=', $from->format($from->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u'))
            ->where('occurred_at', '<', $until->format($until->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u'));
        $this->containsText($query, $rule->match_text);
        $count = (int) $query->count();
        $threshold = $rule->thresholdValue() ?? 1;

        return [
            'state' => $count >= $threshold ? 'breaching' : 'healthy',
            'value' => (float) $count,
            'samples' => $count,
            'reason' => null,
        ];
    }

    /** @param Builder<TelemetryEvent> $query */
    private function containsText(Builder $query, string $text): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
        $query->where(function (Builder $search) use ($pattern): void {
            foreach ([
                'name', 'route', 'service', 'trace_id', 'span_id',
                'payload->message', 'payload->body', 'payload->record->body->stringValue',
                'payload->_beacon->indexed_fields->name',
            ] as $column) {
                $wrapped = $search->getQuery()->getGrammar()->wrap($column);
                $search->orWhereRaw($wrapped." LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }
}
