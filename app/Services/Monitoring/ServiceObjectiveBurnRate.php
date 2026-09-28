<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\ServiceLevelObjective;
use Carbon\CarbonImmutable;

final class ServiceObjectiveBurnRate
{
    /**
     * Measures how fast SLOs burn their error budget.
     *
     * @param  ServiceObjectiveReport  $reports  Reports each SLO over a period.
     */
    public function __construct(private readonly ServiceObjectiveReport $reports) {}

    /**
     * @param  ServiceLevelObjective  $objective
     * @param  CarbonImmutable|null  $until
     * @return array{
     *     status: string,
     *     label: string,
     *     message: string,
     *     short: array<string, mixed>,
     *     long: array<string, mixed>
     * }
     */
    public function forObjective(ServiceLevelObjective $objective, ?CarbonImmutable $until = null): array
    {
        $until ??= CarbonImmutable::now('UTC');
        $short = $this->reports->forPeriod($objective, $until->subHour(), $until);
        $long = $this->reports->forPeriod($objective, $until->subHours(6), $until);
        $shortBurn = $short['burn_rate'];
        $longBurn = $long['burn_rate'];

        if ($shortBurn === null && $longBurn === null) {
            $status = 'no_data';
        } elseif ($shortBurn !== null && $longBurn !== null && $shortBurn >= 14.4 && $longBurn >= 6) {
            $status = 'critical';
        } elseif (($shortBurn !== null && $shortBurn >= 6) || ($longBurn !== null && $longBurn >= 3)) {
            $status = 'warning';
        } else {
            $status = 'healthy';
        }

        return [
            'status' => $status,
            'label' => match ($status) {
                'critical' => 'Fast burn',
                'warning' => 'Budget risk',
                'healthy' => 'Stable burn',
                default => 'Not enough data',
            },
            'message' => match ($status) {
                'critical' => 'The current error rate could exhaust the budget quickly. Investigate the active traffic and recent changes now.',
                'warning' => 'The recent error rate is consuming budget faster than the objective allows. Keep the next release low-risk.',
                'healthy' => 'Recent error consumption is within the objective\'s operating range.',
                default => 'There is not enough eligible request telemetry to estimate burn risk.',
            },
            'short' => $short,
            'long' => $long,
        ];
    }
}
