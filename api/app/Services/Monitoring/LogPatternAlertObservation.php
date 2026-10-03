<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\AlertRule;
use App\Models\TelemetryEvent;
use App\Support\Telemetry\EventTextSearch;
use App\Support\Telemetry\EventTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class LogPatternAlertObservation
{
    /**
     * Count events in the rule's environment (and service) whose text matches the pattern within the window, breaching
     * at the threshold.
     *
     * @param  AlertRule  $rule
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return array{state: string, value: float|null, samples: int, reason: string|null}
     */
    public function measure(AlertRule $rule, CarbonImmutable $from, CarbonImmutable $until): array
    {
        if ($rule->match_text === null || $rule->match_text === '') {
            return ['state' => 'no_data', 'value' => null, 'samples' => 0, 'reason' => 'pattern_unavailable'];
        }

        $query = TelemetryEvent::query()->where('environment_id', $rule->environment_id)
            ->when($rule->service !== null, fn (Builder $events): Builder => $events->where('service', $rule->service))
            ->where('occurred_at', '>=', EventTime::boundary($from))
            ->where('occurred_at', '<', EventTime::boundary($until));
        EventTextSearch::apply($query, $rule->match_text);
        $count = (int) $query->count();
        $threshold = $rule->thresholdValue() ?? 1;

        return [
            'state' => $count >= $threshold ? 'breaching' : 'healthy',
            'value' => (float) $count,
            'samples' => $count,
            'reason' => null,
        ];
    }
}
