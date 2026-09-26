<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Contracts\DnsRecordResolver;
use App\Modules\Monitor\Data\Telemetry\MonitorObservation;
use App\Modules\Monitor\Models\Monitor;
use Throwable;

final class ProbeDnsMonitor
{
    public function __construct(private readonly DnsRecordResolver $dns, private readonly DnsRecordSet $sets) {}

    public function probe(Monitor $monitor): MonitorObservation
    {
        $started = hrtime(true);
        $hostname = $this->sets->hostname($monitor->hostname ?? '');
        $expected = $monitor->dns_expected;
        if ($hostname === null || ! in_array($monitor->dns_record_type, DnsRecordSet::TYPES, true)
            || ! in_array($monitor->dns_match, ['contains', 'exact'], true) || ! is_array($expected) || $expected === []
            || count(array_filter($expected, 'is_string')) !== count($expected)
            || $this->sets->expected($monitor->dns_record_type, implode("\n", $expected)) !== $expected) {
            return new MonitorObservation('unknown', 'target_invalid');
        }
        try {
            $records = $this->dns->records($hostname, $monitor->dns_record_type, $monitor->timeout_seconds);
        } catch (Throwable) {
            $records = null;
        }
        $duration = (hrtime(true) - $started) / 1_000_000;
        if ($records === null || $duration > $monitor->timeout_seconds * 1000) {
            return new MonitorObservation('unknown', 'dns_unavailable', durationMs: $duration, dnsMs: $duration);
        }
        $observed = $this->sets->observed($monitor->dns_record_type, $records);
        if ($observed === null) {
            return new MonitorObservation('unknown', 'dns_response_invalid', durationMs: $duration, dnsMs: $duration);
        }
        $missing = array_values(array_diff($expected, $observed));
        $unexpected = array_values(array_diff($observed, $expected));
        $passed = $missing === [] && ($monitor->dns_match === 'contains' || $unexpected === []);

        return new MonitorObservation($passed ? 'up' : 'down', $passed ? 'passed' : 'dns_mismatch',
            durationMs: $duration, dnsMs: $duration,
            details: [
                'type' => 'dns', 'record_type' => $monitor->dns_record_type, 'match' => $monitor->dns_match,
                'expected_count' => count($expected), 'observed_count' => count($observed),
                'missing_count' => count($missing), 'unexpected_count' => count($unexpected),
            ],
            evidence: ['expected' => $expected, 'observed' => $observed, 'missing' => $missing, 'unexpected' => $unexpected]);
    }
}
