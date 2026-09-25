<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Data\Telemetry\CollectionHealthState;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CollectionHealthSummary
{
    public const DEFAULT_STALE_AFTER_MINUTES = 60;

    /**
     * @return array{
     *     environments: Collection<int, array{environment: Environment, state: CollectionHealthState, description: string}>,
     *     total: int,
     *     counts: array<string, int>,
     *     stale_after_minutes: int,
     * }
     */
    public function forWorkspace(Workspace $workspace, ?Authenticatable $principal = null): array
    {
        $now = CarbonImmutable::now('UTC');
        $staleAfterMinutes = max(1, (int) config('monitor.beacon.telemetry.collection_stale_after_minutes', self::DEFAULT_STALE_AFTER_MINUTES));
        $environments = Environment::forWorkspace($workspace)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace))
            ->with('application:id,name')
            ->withCount(['ingestTokens as active_token_count' => fn (Builder $query): Builder => $query->active()])
            ->orderBy('application_id')->orderBy('name')->orderBy('id')
            ->get(['id', 'application_id', 'name', 'status', 'event_count', 'last_seen_at']);
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

    private function state(Environment $environment, CarbonImmutable $now, int $staleAfterMinutes): CollectionHealthState
    {
        if ($environment->status !== 'active') {
            return CollectionHealthState::Paused;
        }

        if ((int) $environment->active_token_count === 0) {
            return CollectionHealthState::NoToken;
        }

        if ($environment->last_seen_at === null) {
            return CollectionHealthState::Awaiting;
        }

        return $environment->last_seen_at->lt($now->subMinutes($staleAfterMinutes))
            ? CollectionHealthState::Stale
            : CollectionHealthState::Receiving;
    }

    private function description(Environment $environment, CollectionHealthState $state, CarbonImmutable $now, int $staleAfterMinutes): string
    {
        return match ($state) {
            CollectionHealthState::Paused => 'Ingestion is paused for this environment.',
            CollectionHealthState::NoToken => 'Create an active environment token to resume collection.',
            CollectionHealthState::Awaiting => 'No accepted telemetry has been received yet.',
            CollectionHealthState::Stale => 'No event received in the last '.$staleAfterMinutes.' minutes; last seen '.$environment->last_seen_at->diffForHumans($now).'.',
            CollectionHealthState::Receiving => 'Last event received '.$environment->last_seen_at->diffForHumans($now).'.',
        };
    }
}
