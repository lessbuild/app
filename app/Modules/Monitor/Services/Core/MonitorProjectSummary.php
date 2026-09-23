<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectProductSummaryProvider;
use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Monitor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;

final class MonitorProjectSummary implements ProjectProductSummaryProvider
{
    public function __construct(private readonly MonitorProjectLink $applications) {}

    public function summarize(PlatformUser $user, Project $project): ?ProjectProductSnapshot
    {
        $authorizedApplications = $this->applications->accessibleApplications($user, $project);

        if ($authorizedApplications->isEmpty()) {
            return null;
        }

        $applicationIds = $authorizedApplications->modelKeys();
        $environments = Environment::query()
            ->whereIn('application_id', $applicationIds)
            ->where('status', 'active')
            ->get(['id', 'application_id', 'last_seen_at']);
        $environmentIds = $environments->modelKeys();
        $monitors = $environmentIds === []
            ? collect()
            : Monitor::query()
                ->whereIn('environment_id', $environmentIds)
                ->with('environment:id,status')
                ->get(['id', 'environment_id', 'type', 'health', 'enabled', 'checked_at', 'next_check_at', 'interval_minutes']);
        $monitorIds = $monitors->modelKeys();
        $ruleIds = $environmentIds === []
            ? []
            : AlertRule::query()->whereIn('environment_id', $environmentIds)->select('id');
        $incidents = Incident::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->where(fn ($query) => $query
                ->whereIn('alert_rule_id', $ruleIds ?: [0])
                ->orWhereIn('monitor_id', $monitorIds ?: [0]))
            ->get(['id', 'opened_at', 'updated_at']);

        $healthCounts = $monitors
            ->map(fn (Monitor $monitor): string => mb_strtolower($monitor->healthLabel()))
            ->countBy();
        $downCount = $healthCounts->get('down', 0);
        $upCount = $healthCounts->get('up', 0);
        $unknownCount = $healthCounts->get('unknown', 0);
        $pausedCount = $healthCounts->get('paused', 0);
        $openCount = $incidents->count();
        $state = $openCount > 0 || $downCount > 0 || $unknownCount > 0
            ? ProjectProductSnapshotState::Attention
            : ($upCount > 0 ? ProjectProductSnapshotState::Current : ProjectProductSnapshotState::Empty);

        if ($environmentIds === []) {
            $detail = __('No active environments are connected to Monitor yet.');
        } elseif ($monitors->isEmpty()) {
            $detail = $openCount > 0
                ? trans_choice(':count open incident|:count open incidents', $openCount, ['count' => $openCount])
                : __('No health checks are configured yet.');
        } else {
            $detail = __(':incidents open incidents · :up checks up · :down checks down · :unknown unknown · :paused paused', [
                'incidents' => $openCount,
                'up' => $upCount,
                'down' => $downCount,
                'unknown' => $unknownCount,
                'paused' => $pausedCount,
            ]);
        }

        $updatedAt = null;
        foreach ($environments->pluck('last_seen_at')->merge($monitors->pluck('checked_at'))->merge($incidents->pluck('updated_at')) as $date) {
            if ($date instanceof CarbonInterface && ($updatedAt === null || $date->greaterThan($updatedAt))) {
                $updatedAt = $date;
            }
        }

        $application = $authorizedApplications->first();

        return new ProjectProductSnapshot(
            title: __('Monitor health and incidents'),
            detail: $detail,
            state: $state,
            updatedAt: $updatedAt,
            url: Route::has('monitor.applications.show') ? route('monitor.applications.show', $application->getKey()) : null,
        );
    }
}
