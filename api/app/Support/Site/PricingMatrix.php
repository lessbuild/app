<?php

declare(strict_types=1);

namespace App\Support\Site;

use App\Platform\Catalog\Tier;

/**
 * The "Key features" table on the pricing page: for each service, groups of rows with one value per tier, read from the
 * tiers' own limits and feature flags, so the table can't drift from what a plan actually allows.
 */
final class PricingMatrix
{
    /**
     * Build a service's table.
     *
     * @param  string  $service  The service's key.
     * @param  list<Tier>  $tiers  The service's tiers, in order.
     * @return list<array{group: string, rows: list<array{label: string, values: list<bool|string>}>}>
     */
    public static function for(string $service, array $tiers): array
    {
        return array_map(fn (array $group): array => [
            'group' => $group[0],
            'rows' => array_map(fn (array $row): array => [
                'label' => $row[0],
                'values' => array_map(fn (Tier $tier): bool|string => self::value($tier, $row[1], $row[2] ?? null), $tiers),
            ], $group[1]),
        ], self::rows($service));
    }

    /**
     * The rows for a service: [group, [[label, how to read it, its key], …]]. "flag" is a feature flag, "count", "days",
     * "minutes" and "scans" read a limit (null is unlimited), and "all" is included in every tier.
     *
     * @param  string  $service
     * @return list<array{0: string, 1: list<array{0: string, 1: string, 2?: string}>}>
     */
    private static function rows(string $service): array
    {
        return match ($service) {
            'deploy' => [
                [__('Limits'), [
                    [__('Servers'), 'count', 'infrastructure.servers.max'],
                    [__('Websites'), 'count', 'deploy.websites.max'],
                    [__('Preview environments at once'), 'count', 'deploy.previews.max'],
                    [__('Team members'), 'count', 'account.members.max'],
                ]],
                [__('Releases'), [
                    [__('Git deploys, rollback and promotion'), 'flag', 'deploy.releases'],
                    [__('Queue workers and schedulers'), 'flag', 'deploy.workers'],
                    [__('Idle hibernation'), 'flag', 'deploy.hibernation'],
                    [__('Scheduled deploys'), 'flag', 'deploy.scheduled'],
                    [__('Autoscaling and scaling schedules'), 'flag', 'deploy.scaling'],
                ]],
                [__('Operations'), [
                    [__('Managed backups'), 'flag', 'deploy.backups'],
                    [__('Databases and caches per environment'), 'flag', 'deploy.resources'],
                    [__('Server budgets'), 'flag', 'deploy.cost_controls'],
                    [__('White-label client reports'), 'flag', 'account.white_label'],
                    [__('Load balancers'), 'flag', 'deploy.high_availability'],
                ]],
            ],
            'infrastructure' => [
                [__('Included'), [
                    [__('Servers at 10 cloud providers, or your own over SSH'), 'all'],
                    [__('Websites with domains and automatic TLS'), 'all'],
                    [__('Databases and read replicas'), 'all'],
                    [__('Commands and a browser terminal'), 'all'],
                    [__('Costs and right-sizing'), 'all'],
                ]],
            ],
            'monitoring' => [
                [__('Volume'), [
                    [__('Events per month'), 'count', 'monitoring.events.monthly'],
                    [__('Retention'), 'days', 'monitoring.retention.days'],
                    [__('Applications'), 'count', 'monitoring.apps.max'],
                    [__('Seats'), 'count', 'account.members.max'],
                    [__('Dashboards'), 'count', 'monitoring.dashboards.max'],
                ]],
                [__('Alerting'), [
                    [__('Uptime checks, incidents and status pages'), 'all'],
                    [__('Daily issue digest'), 'flag', 'monitoring.issue_digest'],
                    [__('Escalation steps'), 'count', 'monitoring.escalation_steps.max'],
                    [__('Recent deploys shown on incidents'), 'minutes', 'monitoring.deployment_context.minutes'],
                    [__('SLO burn-rate alerts'), 'flag', 'monitoring.slo_burn_rate'],
                    [__('Anomaly detection'), 'flag', 'monitoring.anomalies'],
                    [__('Log pattern alerts'), 'flag', 'monitoring.log_patterns'],
                    [__('Telemetry volume and freshness alerts'), 'flag', 'monitoring.guardrails'],
                    [__('SLO reports'), 'flag', 'monitoring.slo_reports'],
                ]],
            ],
            'analytics' => [
                [__('Limits'), [
                    [__('Sites'), 'count', 'analytics.sites.max'],
                    [__('Pageviews per month'), 'count', 'analytics.pageviews.monthly'],
                    [__('Event history'), 'days', 'analytics.retention.days'],
                ]],
                [__('Reports'), [
                    [__('Cookieless tracking'), 'all'],
                    [__('Goals, funnels and campaigns'), 'all'],
                    [__('Explore: paths, retention, attribution and A/B tests'), 'all'],
                    [__('Email reports, alerts and shared links'), 'all'],
                ]],
            ],
            'security' => [
                [__('Scanning'), [
                    [__('Scans run'), 'scans', 'security.scan.hours'],
                    [__('Vulnerable packages and domain checks'), 'all'],
                    [__('Secret scanning'), 'flag', 'security.secrets'],
                    [__('Server hardening and update windows'), 'flag', 'security.servers'],
                ]],
                [__('Protection'), [
                    [__('Block risky deploys'), 'flag', 'security.deploy_gate'],
                    [__('Automatic attack blocking'), 'flag', 'security.autoblock'],
                    [__('Firewall and bot controls'), 'flag', 'security.waf'],
                    [__('Access reviews'), 'flag', 'security.access_reviews'],
                    [__('Compliance evidence'), 'flag', 'security.compliance'],
                ]],
            ],
            'audit' => [
                [__('Limits'), [
                    [__('Audits per month'), 'count', 'audit.runs.monthly'],
                    [__('Competitors per audit'), 'count', 'audit.competitors'],
                    [__('Pages per site'), 'count', 'audit.pages'],
                    [__('Saved audits'), 'count', 'audit.audits.max'],
                ]],
                [__('Reports'), [
                    [__('Scores, findings and journey replays'), 'all'],
                    [__('Monthly scheduled audits'), 'flag', 'audit.schedule.monthly'],
                    [__('Weekly scheduled audits'), 'flag', 'audit.schedule.weekly'],
                    [__('White-label PDF reports'), 'flag', 'audit.white_label'],
                ]],
            ],
            default => [],
        };
    }

    /**
     * Read one cell: a flag as yes or no, a limit as a number (or Unlimited, or no when it's zero).
     *
     * @param  Tier  $tier
     * @param  string  $kind
     * @param  string|null  $key
     * @return bool|string
     */
    private static function value(Tier $tier, string $kind, ?string $key): bool|string
    {
        if ($kind === 'all') {
            return true;
        }
        if ($kind === 'flag') {
            return in_array($key, $tier->flags, true);
        }
        if (! array_key_exists((string) $key, $tier->limits)) {
            return false;
        }
        $limit = $tier->limits[(string) $key];
        if ($limit === null) {
            return __('Unlimited');
        }
        $limit = (int) $limit;
        if ($limit === 0) {
            return false;
        }

        return match ($kind) {
            'days' => $limit % 365 === 0 ? trans_choice(':count year|:count years', intdiv($limit, 365)) : trans_choice(':count day|:count days', $limit),
            'minutes' => $limit % 60 === 0 ? trans_choice(':count hour|:count hours', intdiv($limit, 60)) : trans_choice(':count minute|:count minutes', $limit),
            'scans' => match (true) {
                $limit >= 168 => __('Weekly'),
                $limit >= 24 => __('Daily'),
                default => __('Every :count hours', ['count' => $limit]),
            },
            default => self::compact($limit),
        };
    }

    /**
     * Write a big number briefly: 500K, 10M.
     *
     * @param  int  $number
     * @return string
     */
    private static function compact(int $number): string
    {
        return match (true) {
            $number >= 1_000_000 && $number % 100_000 === 0 => rtrim(rtrim(number_format($number / 1_000_000, 1), '0'), '.').'M',
            $number >= 1_000 && $number % 100 === 0 => rtrim(rtrim(number_format($number / 1_000, 1), '0'), '.').'K',
            default => number_format($number),
        };
    }
}
