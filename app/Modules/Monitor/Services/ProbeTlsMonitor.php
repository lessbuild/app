<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Contracts\TlsCertificateInspector;
use App\Modules\Monitor\Data\Telemetry\MonitorObservation;
use App\Modules\Monitor\Models\Monitor;
use Carbon\CarbonImmutable;
use Throwable;

final class ProbeTlsMonitor
{
    public function __construct(private readonly PublicHttpTarget $targets, private readonly TlsCertificateInspector $inspector) {}

    public function probe(Monitor $monitor): MonitorObservation
    {
        $started = hrtime(true);
        $hostname = $monitor->hostname ?? '';
        $url = 'https://'.$hostname.':'.$monitor->tls_port.'/';
        $configuration = $this->targets->parse($url);
        if ($monitor->tls_expiry_days < 1 || $monitor->tls_expiry_days > 90 || $configuration === null
            || $configuration['host'] !== $hostname || $configuration['port'] !== $monitor->tls_port) {
            return new MonitorObservation('unknown', 'target_invalid');
        }
        try {
            $target = $this->targets->resolve($url);
            $dnsMs = (hrtime(true) - $started) / 1_000_000;
            if ($target['error'] !== null) {
                return new MonitorObservation('unknown', $target['error'], durationMs: $dnsMs, dnsMs: $dnsMs);
            }
            $remaining = (int) floor($monitor->timeout_seconds * 1000 - $dnsMs);
            if ($remaining < 1) {
                return new MonitorObservation('unknown', 'dns_unavailable', durationMs: $dnsMs, dnsMs: $dnsMs);
            }
            $certificate = $this->inspector->inspect($target['host'], $target['address'], $target['port'], $remaining);
            $duration = (hrtime(true) - $started) / 1_000_000;
            if ($certificate->error !== null) {
                return new MonitorObservation($certificate->error === 'tls_connection_failed' ? 'down' : 'unknown',
                    $certificate->error, durationMs: $duration, dnsMs: $dnsMs, connectMs: $certificate->connectMs);
            }
            if ($certificate->validFrom === null || $certificate->validUntil === null
                || $certificate->validFrom >= $certificate->validUntil || $certificate->fingerprint === null) {
                return new MonitorObservation('unknown', 'tls_certificate_unavailable', durationMs: $duration, dnsMs: $dnsMs);
            }
            $now = CarbonImmutable::now('UTC')->getTimestamp();
            $reason = match (true) {
                $certificate->validFrom > $now => 'tls_not_yet_valid',
                $certificate->validUntil <= $now => 'tls_expired',
                $certificate->validUntil <= $now + $monitor->tls_expiry_days * 86400 => 'tls_expiring',
                default => 'passed',
            };

            return new MonitorObservation($reason === 'passed' ? 'up' : 'down', $reason,
                durationMs: $duration, dnsMs: $dnsMs, connectMs: $certificate->connectMs,
                details: [
                    'type' => 'tls', 'valid_from' => CarbonImmutable::createFromTimestampUTC($certificate->validFrom)->toISOString(),
                    'valid_until' => CarbonImmutable::createFromTimestampUTC($certificate->validUntil)->toISOString(),
                    'days_remaining' => (int) floor(($certificate->validUntil - $now) / 86400),
                    'expiry_days' => $monitor->tls_expiry_days, 'fingerprint_sha256' => $certificate->fingerprint,
                ]);
        } catch (Throwable) {
            return new MonitorObservation('unknown', 'checker_unavailable', durationMs: (hrtime(true) - $started) / 1_000_000);
        }
    }
}
