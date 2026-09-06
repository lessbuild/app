<?php

namespace App\Presenters;

use App\Models\Website;

final class StatusComponentPresenter
{
    /**
     * Present a status-page website without executing additional queries.
     *
     * @param  Website  $website  A website with its display-name pivot and recent health-check counts loaded.
     * @return array{name: string, operational: bool, status: string, uptime_30d: ?float, checked_at: ?string}
     */
    public function present(Website $website): array
    {
        $total = (int) $website->recent_health_checks_count;
        $successful = (int) $website->successful_recent_health_checks_count;
        $operational = $website->provisioning_status === Website::STATUS_ACTIVE
            && (! $website->health_check_enabled || $website->health_status !== Website::HEALTH_UNHEALTHY);

        return [
            'name' => $website->pivot->display_name ?: $website->name,
            'operational' => $operational,
            'status' => $operational ? 'Operational' : 'Degraded',
            'uptime_30d' => $total > 0 ? round(($successful / $total) * 100, 3) : null,
            'checked_at' => $website->health_last_checked_at?->toIso8601String(),
        ];
    }
}
