<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectConnectionDiagnosticProvider;
use App\Core\Data\Connections\ProjectConnectionDiagnostic;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectResource;
use App\Modules\Monitor\Data\Telemetry\AlertMetric;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Services\AlertObservation;
use App\Modules\Monitor\Services\WorkspaceUsage;
use Carbon\CarbonImmutable;

final class MonitorProjectConnectionDiagnosticProvider implements ProjectConnectionDiagnosticProvider
{
    public function __construct(
        private readonly MonitorProjectLink $access,
        private readonly AlertObservation $observations,
        private readonly WorkspaceUsage $usage,
    ) {}

    public function diagnose(
        PlatformUser $user,
        ProjectConnection $connection,
        ProjectResource $resource,
    ): ?ProjectConnectionDiagnostic {
        if ((string) $resource->project_id !== (string) $connection->project_id) {
            return null;
        }

        $environment = $this->access->accessibleEnvironment($user, $resource);
        if ($environment === null) {
            return null;
        }

        $now = CarbonImmutable::now('UTC');
        $workspace = $environment->application?->workspace;

        if ($workspace !== null) {
            $usage = $this->usage->summary($workspace, $now);
            $quotaDiagnostic = $this->quotaDiagnostic($usage);

            if ($quotaDiagnostic !== null) {
                return $quotaDiagnostic;
            }
        }

        if ($environment->last_seen_at === null) {
            return new ProjectConnectionDiagnostic(
                tone: 'warning',
                status: __('No telemetry'),
                summary: __('Monitor has not received telemetry for this environment.'),
                detail: __('The environment is connected, but no incoming event has been recorded yet.'),
                nextStep: __('Check the Monitor ingest configuration and send a test event.'),
                lastAttemptAt: null,
                lastSucceededAt: null,
            );
        }

        $freshnessRules = AlertRule::query()
            ->where('environment_id', $environment->getKey())
            ->where('metric', AlertMetric::TelemetryFreshness->value)
            ->where('enabled', true)
            ->whereNotNull('monitoring_since')
            ->orderBy('threshold')
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($freshnessRules as $rule) {
            if ($this->observations->measure($rule, $now)['state'] !== 'breaching') {
                continue;
            }

            return new ProjectConnectionDiagnostic(
                tone: 'warning',
                status: __('Telemetry stale'),
                summary: __('Monitor telemetry is outside its configured freshness window.'),
                detail: __('No qualifying event was received within the configured :seconds second freshness window.', [
                    'seconds' => (int) $rule->threshold,
                ]),
                nextStep: __('Check the Monitor ingest configuration and event source, then review this environment’s freshness alert.'),
                lastAttemptAt: null,
                lastSucceededAt: null,
                lastObservedAt: $environment->last_seen_at,
                lastObservedLabel: __('Latest telemetry'),
                priority: 20,
            );
        }

        return new ProjectConnectionDiagnostic(
            tone: 'info',
            status: __('Telemetry received'),
            summary: __('Monitor has received telemetry for this environment.'),
            detail: __('The latest event is recorded. Freshness is compared with enabled Monitor freshness alerts.'),
            nextStep: null,
            lastAttemptAt: null,
            lastSucceededAt: null,
            lastObservedAt: $environment->last_seen_at,
            lastObservedLabel: __('Latest telemetry'),
            priority: 100,
        );
    }

    /** @param array{event_count: int, event_limit: int, percentage: int, state: string, plan_available: bool, event_limit_is_finite: bool, period_start: CarbonImmutable} $usage */
    private function quotaDiagnostic(array $usage): ?ProjectConnectionDiagnostic
    {
        if (! $usage['plan_available']
            || ! $usage['event_limit_is_finite']
            || ! in_array($usage['state'], ['warning', 'limit'], true)) {
            return null;
        }

        $limitReached = $usage['state'] === 'limit';
        $period = $usage['period_start']->translatedFormat('F Y');
        $detail = __('Workspace Monitor usage for :period is :used of :limit events (:percentage%). This allowance is shared by the workspace’s Monitor applications.', [
            'period' => $period,
            'used' => number_format($usage['event_count']),
            'limit' => number_format($usage['event_limit']),
            'percentage' => $usage['percentage'],
        ]);

        return new ProjectConnectionDiagnostic(
            tone: $limitReached ? 'danger' : 'warning',
            status: $limitReached ? __('Usage limit reached') : __('Approaching usage limit'),
            summary: $limitReached
                ? __('This workspace reached its Monitor event allowance for the month.')
                : __('This workspace is nearing its Monitor event allowance for the month.'),
            detail: $detail,
            nextStep: $limitReached
                ? __('Review the Monitor plan or reduce telemetry volume across this workspace. The allowance resets at the start of the next UTC month.')
                : __('Review the Monitor plan or reduce telemetry volume across this workspace before the allowance is reached.'),
            lastAttemptAt: null,
            lastSucceededAt: null,
            priority: $limitReached ? 16 : 18,
        );
    }
}
