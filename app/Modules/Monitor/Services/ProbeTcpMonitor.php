<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Contracts\TcpConnector;
use App\Modules\Monitor\Data\Telemetry\MonitorObservation;
use App\Modules\Monitor\Models\Monitor;
use Throwable;

final class ProbeTcpMonitor
{
    public function __construct(private readonly PublicHttpTarget $targets, private readonly TcpConnector $connector) {}

    public function probe(Monitor $monitor): MonitorObservation
    {
        $started = hrtime(true);
        $hostname = $monitor->hostname ?? '';
        $port = $monitor->tcp_port;
        $url = 'https://'.$hostname.':'.$port.'/';
        $configuration = $this->targets->parse($url);
        if ($port < 1 || $port > 65535 || $monitor->timeout_seconds < 1 || $monitor->timeout_seconds > 20 || $configuration === null
            || $configuration['host'] !== $hostname || $configuration['port'] !== $port) {
            return new MonitorObservation('unknown', 'target_invalid');
        }

        try {
            $target = $this->targets->resolve($url);
        } catch (Throwable) {
            $duration = (hrtime(true) - $started) / 1_000_000;

            return new MonitorObservation('unknown', 'dns_unavailable', durationMs: $duration, dnsMs: $duration);
        }
        $dnsMs = (hrtime(true) - $started) / 1_000_000;
        if ($target['error'] !== null) {
            return new MonitorObservation('unknown', $target['error'], durationMs: $dnsMs, dnsMs: $dnsMs);
        }
        $remaining = (int) floor($monitor->timeout_seconds * 1000 - $dnsMs);
        if ($remaining < 1) {
            return new MonitorObservation('unknown', 'dns_unavailable', durationMs: $dnsMs, dnsMs: $dnsMs);
        }

        try {
            $connected = $this->connector->connect($target['address'], $port, $remaining);
            $duration = (hrtime(true) - $started) / 1_000_000;

            return new MonitorObservation($connected ? 'up' : 'down', $connected ? 'tcp_connected' : 'tcp_connection_failed',
                durationMs: $duration, dnsMs: $dnsMs, connectMs: max(0, $duration - $dnsMs),
                details: ['type' => 'tcp', 'port' => $port]);
        } catch (Throwable) {
            return new MonitorObservation('unknown', 'checker_unavailable', durationMs: (hrtime(true) - $started) / 1_000_000);
        }
    }
}
