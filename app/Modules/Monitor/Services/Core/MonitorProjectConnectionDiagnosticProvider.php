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
use Carbon\CarbonImmutable;

final class MonitorProjectConnectionDiagnosticProvider implements ProjectConnectionDiagnosticProvider
{
    public function __construct(
        private readonly MonitorProjectLink $access,
        private readonly AlertObservation $observations,
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

        $now = CarbonImmutable::now('UTC');
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
        );
    }
}
