<?php

declare(strict_types=1);

namespace App\Queries\Telemetry;

use App\Enums\CollectionHealthState;
use App\Models\Environment;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CollectionHealthQuery
{
    public const DEFAULT_STALE_AFTER_MINUTES = 60;

    /**
     * Check whether each of the project's environments is receiving telemetry, with counts of each state and the stale
     * window used.
     *
     * @param  Project  $project
     * @return array{
     *     environments: Collection<int, array{environment: Environment, state: CollectionHealthState, description: string}>,
     *     total: int,
     *     counts: array<string, int>,
     *     stale_after_minutes: int,
     * }
     */
    public function handle(Project $project): array
    {
        $now = CarbonImmutable::now('UTC');
        $staleAfterMinutes = max(1, (int) config('monitoring.telemetry.collection_stale_after_minutes', self::DEFAULT_STALE_AFTER_MINUTES));
        $environments = Environment::query()->where('project_id', $project->id)
            ->withCount(['ingestTokens as active_token_count' => fn (Builder $query) => $query->whereNull('revoked_at')
                ->where(fn (Builder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()))])
            ->orderBy('name')->orderBy('id')->get();
        $items = $environments->map(function (Environment $environment) use ($now, $staleAfterMinutes): array {
            $state = $this->state($environment, $now, $staleAfterMinutes);

            return [
                'environment' => $environment,
                'state' => $state,
                'description' => $this->description($environment, $state, $now, $staleAfterMinutes),
            ];
        });
        $counts = array_fill_keys(array_map(static fn (CollectionHealthState $state): string => $state->value, CollectionHealthState::cases()), 0);
        foreach ($items as $item) {
            $counts[$item['state']->value]++;
        }

        return [
            'environments' => $items,
            'total' => $items->count(),
            'counts' => $counts,
            'stale_after_minutes' => $staleAfterMinutes,
        ];
    }

    /**
     * Determine whether an environment is receiving telemetry: no usable ingest key, nothing received yet, nothing
     * within the stale window, or receiving.
     *
     * @param  Environment  $environment
     * @param  CarbonImmutable  $now
     * @param  int  $staleAfterMinutes
     * @return CollectionHealthState
     */
    private function state(Environment $environment, CarbonImmutable $now, int $staleAfterMinutes): CollectionHealthState
    {
        if ((int) $environment->getAttribute('active_token_count') === 0) {
            return CollectionHealthState::NoToken;
        }

        if ($environment->telemetry_last_received_at === null) {
            return CollectionHealthState::Awaiting;
        }

        return $environment->telemetry_last_received_at->lt($now->subMinutes($staleAfterMinutes))
            ? CollectionHealthState::Stale
            : CollectionHealthState::Receiving;
    }

    /**
     * Explain the state in one line, with when the last event arrived.
     *
     * @param  Environment  $environment
     * @param  CollectionHealthState  $state
     * @param  CarbonImmutable  $now
     * @param  int  $staleAfterMinutes
     * @return string
     */
    private function description(Environment $environment, CollectionHealthState $state, CarbonImmutable $now, int $staleAfterMinutes): string
    {
        return match ($state) {
            CollectionHealthState::NoToken => __('Create an ingest key to start collecting.'),
            CollectionHealthState::Awaiting => __('Nothing received yet.'),
            CollectionHealthState::Stale => __('Nothing received in the last :minutes minutes; last event :time.', ['minutes' => $staleAfterMinutes, 'time' => $environment->telemetry_last_received_at?->diffForHumans()]),
            CollectionHealthState::Receiving => __('Last event :time.', ['time' => $environment->telemetry_last_received_at?->diffForHumans()]),
        };
    }
}
