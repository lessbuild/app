<?php

namespace App\Modules\Monitor\Data\Telemetry;

final readonly class MonitorObservation
{
    /** @param array<string, mixed> $details Safe summary included in incidents and notifications.
     * @param  array<string, list<string>>  $evidence  Private record values stored only in encrypted check history.
     */
    public function __construct(
        public string $outcome,
        public string $reason,
        public ?int $httpStatus = null,
        public ?float $durationMs = null,
        public ?float $dnsMs = null,
        public ?float $connectMs = null,
        public ?float $ttfbMs = null,
        public array $details = [],
        public array $evidence = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome, 'reason' => $this->reason, 'http_status' => $this->httpStatus,
            'duration_ms' => $this->durationMs, 'dns_ms' => $this->dnsMs,
            'connect_ms' => $this->connectMs, 'ttfb_ms' => $this->ttfbMs,
            ...($this->details === [] ? [] : ['details' => $this->details]),
        ];
    }

    public static function label(?string $reason): string
    {
        return match ($reason) {
            'queue_healthy' => 'Fresh queue and worker signals meet the configured thresholds',
            'queue_incomplete' => 'Awaiting fresh signals or required metrics; health is unknown',
            'queue_report_missing' => 'Queue collector report is missing or stale',
            'queue_workers_missing' => 'Too few workers have a fresh heartbeat',
            'queue_pending' => 'Ready-job backlog exceeds the threshold',
            'queue_delayed' => 'Delayed-job backlog exceeds the threshold',
            'queue_reserved' => 'Reserved / in-flight jobs exceed the threshold',
            'queue_failed' => 'Failed / dead-letter backlog exceeds the threshold',
            'queue_oldest_wait_seconds' => 'Oldest ready-job wait exceeds the threshold',
            'queue_runtime' => 'A live worker has exceeded the busy-job duration threshold',
            'heartbeat_success' => 'Job reported success',
            'heartbeat_failure' => 'Job reported failure',
            'heartbeat_timeout' => 'Started run did not finish within the grace period',
            'heartbeat_missing' => 'Expected heartbeat did not arrive before its deadline',
            'passed' => 'All assertions passed',
            'unexpected_status' => 'Unexpected HTTP status',
            'body_mismatch' => 'Required response text missing',
            'too_slow' => 'Response exceeded duration threshold',
            'connection_failed' => 'Connection, TLS or timeout failure from this checker',
            'dns_unavailable' => 'DNS could not be resolved by this checker',
            'dns_mismatch' => 'DNS records do not match the expected set',
            'dns_response_invalid' => 'DNS answer was invalid or exceeded the check limits',
            'tls_connection_failed' => 'TLS connection, certificate validation or timeout failure',
            'tls_certificate_unavailable' => 'Verified certificate metadata unavailable',
            'tls_expiring' => 'Certificate expires within the configured warning period',
            'tls_expired' => 'Certificate has expired',
            'tls_not_yet_valid' => 'Certificate is not yet valid',
            'tcp_connected' => 'TCP connection established',
            'tcp_connection_failed' => 'TCP connection failed or timed out',
            'target_not_public', 'target_invalid' => 'Target blocked by public-network safety checks',
            'response_too_large' => 'Response exceeded the 512 KiB check limit',
            'worker_interrupted' => 'Checker interrupted; target status unknown',
            'check_missed' => 'Check missed its execution window',
            'source_changed' => 'Check cancelled: source changed or paused',
            'checker_unavailable' => 'Checker unavailable',
            default => 'Awaiting a check',
        };
    }
}
