<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

/** Monitor's plans, prices and limits, unchanged so nobody's bill moves at cutover. */
final class MonitoringCatalog
{
    public static function billing(): ServiceBilling
    {
        return new ServiceBilling(
            tiers: [
                self::tier('free', 'Free', 0, __('A clear starting point for small projects.'), [__('500K events / month'), __('7-day retention'), __('1 application')], 500_000, 7, 1, 1, 1, 0, ['monitoring.issue_digest']),
                self::tier('pro', 'Pro', 29, __('For teams shipping production software every day.'), [__('10M events / month'), __('30-day retention'), __('Unlimited applications'), __('SLO burn-rate alerts'), __('Anomaly detection')], 10_000_000, 30, null, 5, 5, 60, ['monitoring.issue_digest', 'monitoring.slo_burn_rate', 'monitoring.anomalies', 'monitoring.log_patterns', 'monitoring.guardrails']),
                self::tier('team', 'Team', 99, __('Shared observability for growing engineering teams.'), [__('50M events / month'), __('90-day retention'), __('15 seats'), __('SLO reports')], 50_000_000, 90, null, 15, 15, 120, ['monitoring.issue_digest', 'monitoring.slo_burn_rate', 'monitoring.anomalies', 'monitoring.log_patterns', 'monitoring.guardrails', 'monitoring.slo_reports']),
                self::tier('scale', 'Scale', 299, __('High-volume telemetry with a direct line to our team.'), [__('180M events / month'), __('180-day retention'), __('Unlimited seats'), __('Priority support')], 180_000_000, 180, null, null, null, 240, ['monitoring.issue_digest', 'monitoring.slo_burn_rate', 'monitoring.anomalies', 'monitoring.log_patterns', 'monitoring.guardrails', 'monitoring.slo_reports']),
            ],
            meters: [new Meter('monitoring.events', __('Events'), __('events'), 'monitoring.events.monthly')],
        );
    }

    /**
     * @param  list<string>  $features
     * @param  list<string>  $flags
     */
    private static function tier(string $key, string $name, int $dollars, string $description, array $features, int $events, int $retention, ?int $apps, ?int $seats, ?int $dashboards, int $deploymentContextMinutes, array $flags): Tier
    {
        return new Tier($key, $name, $dollars * 100, $description, $features, ['monitoring.events.monthly' => $events, 'monitoring.retention.days' => $retention, 'monitoring.apps.max' => $apps, 'account.members.max' => $seats, 'monitoring.dashboards.max' => $dashboards, 'monitoring.deployment_context.minutes' => $deploymentContextMinutes], $flags);
    }
}
