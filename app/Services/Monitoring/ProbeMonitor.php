<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\Monitoring\MonitorObservation;
use App\Models\Monitor;

final class ProbeMonitor
{
    public function __construct(
        private readonly ProbeHttpMonitor $http,
        private readonly ProbeDnsMonitor $dns,
        private readonly ProbeTlsMonitor $tls,
        private readonly ProbeTcpMonitor $tcp,
    ) {}

    public function probe(Monitor $monitor): MonitorObservation
    {
        return match ($monitor->type) {
            'http' => $this->http->probe($monitor),
            'dns' => $this->dns->probe($monitor),
            'tls' => $this->tls->probe($monitor),
            'tcp' => $this->tcp->probe($monitor),
            default => new MonitorObservation('unknown', 'target_invalid'),
        };
    }
}
