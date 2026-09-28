<?php

declare(strict_types=1);

namespace App\Support\Monitoring;

use App\Data\Monitoring\MonitorObservation;

/** Plain-text descriptions of a monitor's configuration and observations, for pages and alert emails. */
final class ObservationText
{
    /**
     * What a monitor checked, from the snapshot taken when an incident opened.
     *
     * @param  array<string, mixed>  $snapshot
     * @return string
     */
    public static function configuration(array $snapshot): string
    {
        $value = static fn (string $key): string => self::text($snapshot[$key] ?? null);
        $settings = is_array($snapshot['queue_settings'] ?? null) ? $snapshot['queue_settings'] : [];

        $what = match ($snapshot['type'] ?? 'http') {
            'dns' => __('DNS :type · :match · :count expected records', ['type' => $value('dns_record_type'), 'match' => $value('dns_match'), 'count' => $value('expected_count')]),
            'tcp' => __('TCP port :port · connection only', ['port' => $value('tcp_port')]),
            'tls' => __('TLS certificate · expiry threshold :days days', ['days' => $value('tls_expiry_days')]),
            'queue' => __('Queue / workers · :queue · report timeout :seconds sec · at least :workers live workers', ['queue' => $value('queue_name'), 'seconds' => self::text($settings['report_timeout_seconds'] ?? null), 'workers' => self::text($settings['minimum_workers'] ?? null)]),
            'heartbeat' => __('Cron / heartbeat · :schedule · :grace minute grace', [
                'schedule' => $value('heartbeat_schedule') === 'cron' ? $value('heartbeat_cron').' · '.$value('heartbeat_timezone') : __(':minutes minute interval', ['minutes' => $value('heartbeat_interval_minutes')]),
                'grace' => $value('heartbeat_grace_minutes'),
            ]),
            default => __(':method · HTTP :min–:max', ['method' => $value('method'), 'min' => $value('status_min'), 'max' => $value('status_max')]),
        };

        return $what.' · '.__(':open failures to open · :recover passes to recover', ['open' => $value('trigger_checks'), 'recover' => $value('recovery_checks')]);
    }

    /**
     * The type-specific details of one observation. Never includes record values, URLs or keys.
     *
     * @param  array<string, mixed>  $details
     * @return list<string>
     */
    public static function details(array $details): array
    {
        $value = static fn (string $key): string => self::text($details[$key] ?? null);
        $metrics = is_array($details['metrics'] ?? null) ? $details['metrics'] : [];
        $metric = static fn (string $key): string => self::text($metrics[$key] ?? null, __('unknown'));

        return match ($details['type'] ?? null) {
            'queue' => [
                __(':live live workers · :busy busy · :long overlong jobs', ['live' => $value('active_workers'), 'busy' => $value('busy_workers'), 'long' => $value('long_running_workers')]),
                __('Ready :pending · delayed :delayed · reserved :reserved · failed :failed', ['pending' => $metric('pending'), 'delayed' => $metric('delayed'), 'reserved' => $metric('reserved'), 'failed' => $metric('failed')]),
                ...array_map(static fn (mixed $breach): string => MonitorObservation::label(is_string($breach) ? $breach : null), is_array($details['breaches'] ?? null) ? array_values($details['breaches']) : []),
            ],
            'heartbeat' => [__('Heartbeat deadline: :deadline · observed :received', ['deadline' => self::text($details['deadline_at'] ?? null, __('not recorded')), 'received' => $value('received_at')])],
            'dns' => [__(':type · :observed observed / :expected expected · :missing missing · :unexpected additional records', ['type' => $value('record_type'), 'observed' => $value('observed_count'), 'expected' => $value('expected_count'), 'missing' => $value('missing_count'), 'unexpected' => $value('unexpected_count')])],
            'tcp' => [__('TCP port :port · connection only', ['port' => $value('port')])],
            'tls' => [__('Certificate expires :until · :days complete days remaining', ['until' => $value('valid_until'), 'days' => $value('days_remaining')])],
            default => [],
        };
    }

    /**
     * Prints a scalar snapshot value, or the placeholder when the value is missing or isn't printable.
     *
     * @param  mixed  $value
     * @param  string  $default
     * @return string
     */
    private static function text(mixed $value, string $default = '—'): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
