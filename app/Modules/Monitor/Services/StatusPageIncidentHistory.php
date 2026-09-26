<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\StatusPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StatusPageIncidentHistory
{
    public const WINDOW_DAYS = 30;

    /**
     * @param  Collection<int, int|string>  $monitorIds
     * @return array{active: Collection<int, Incident>, resolved: Collection<int, Incident>}
     */
    public function forStatusPage(StatusPage $statusPage, Collection $monitorIds, ?CarbonImmutable $now = null): array
    {
        $monitorIds = $monitorIds->filter()->values();

        if ($monitorIds->isEmpty()) {
            return ['active' => collect(), 'resolved' => collect()];
        }

        $since = ($now ?? CarbonImmutable::now('UTC'))->utc()->subDays(self::WINDOW_DAYS);
        $incidents = Incident::query()
            ->forWorkspace($statusPage->workspace)
            ->whereIn('monitor_id', $monitorIds->all())
            ->where(function (Builder $query) use ($since): void {
                $query->whereIn('status', ['open', 'acknowledged'])
                    ->orWhere(function (Builder $query) use ($since): void {
                        $query->where('status', 'resolved')->where('resolved_at', '>=', $since);
                    });
            })
            ->latest('opened_at')->latest('id')
            ->limit(50)
            ->get(['id', 'monitor_id', 'title', 'status', 'opened_at', 'resolved_at', 'closure_reason']);

        return [
            'active' => $incidents->whereIn('status', ['open', 'acknowledged'])->values(),
            'resolved' => $incidents->where('status', 'resolved')->values(),
        ];
    }
}
