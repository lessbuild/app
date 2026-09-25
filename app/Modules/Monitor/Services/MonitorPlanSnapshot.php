<?php

namespace App\Modules\Monitor\Services;

final class MonitorPlanSnapshot
{
    /** @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function normalize(array $source): array
    {
        $limitSources = [
            'apps' => 'applications',
            'seats' => 'seats',
            'dashboards' => 'dashboards',
            'escalation_steps' => 'escalation_steps',
            'deployment_context_minutes' => 'deployment_context_minutes',
            'event_limit' => 'events_per_month',
            'retention_days' => 'retention_days',
        ];
        $limits = [];

        foreach ($limitSources as $sourceKey => $limitKey) {
            if (! array_key_exists($sourceKey, $source)) {
                continue;
            }

            $value = $source[$sourceKey];
            if (is_numeric($value) && (int) $value >= 0) {
                $limits[$limitKey] = (int) $value;
            } elseif (is_string($value) && mb_strtolower(trim($value)) === 'unlimited') {
                $limits[$limitKey] = null;
            }
        }

        $entitlements = [];
        foreach ([
            'telemetry_guardrails',
            'slo_burn_rate',
            'slo_burn_rate_alerts',
            'slo_reports',
            'anomaly_detection',
            'log_pattern_alerts',
            'audit_log',
            'issue_digest',
        ] as $entitlement) {
            if (($source[$entitlement] ?? false) === true) {
                $entitlements[] = $entitlement;
            }
        }

        return [
            ...$source,
            'entitlements' => $entitlements,
            'limits' => $limits,
        ];
    }

    /** @return array<string, mixed>|null */
    public function forPlan(string $planKey): ?array
    {
        $plan = config('monitor.beacon.plans.'.$planKey);

        return is_array($plan) ? $this->normalize($plan) : null;
    }
}
