<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\Monitoring\MonitorObservation;
use App\Models\Monitor;

final class ProbeMonitor
{
    /**
     * Create a new ProbeMonitor instance.
     *
     * Runs the check for any monitor type.
     *
     * @param  ProbeHttpMonitor  $http  HTTP checks.
     * @param  ProbeDnsMonitor  $dns  DNS checks.
     * @param  ProbeTlsMonitor  $tls  TLS certificate checks.
     * @param  ProbeTcpMonitor  $tcp  TCP port checks.
     * @param  ProbeFlowMonitor  $flow  Runs multi-step checks.
     */
    public function __construct(
        private readonly ProbeHttpMonitor $http,
        private readonly ProbeDnsMonitor $dns,
        private readonly ProbeTlsMonitor $tls,
        private readonly ProbeTcpMonitor $tcp,
        private readonly ProbeFlowMonitor $flow,
    ) {}

    /**
     * Run the check for the monitor's type. Heartbeat and queue monitors aren't probed.
     *
     * @param  Monitor  $monitor
     * @return MonitorObservation
     */
    public function probe(Monitor $monitor): MonitorObservation
    {
        return match ($monitor->type) {
            'http' => $this->http->probe($monitor),
            'dns' => $this->dns->probe($monitor),
            'tls' => $this->tls->probe($monitor),
            'tcp' => $this->tcp->probe($monitor),
            'flow' => $this->flow->probe($monitor),
            default => new MonitorObservation('unknown', 'target_invalid'),
        };
    }
}
