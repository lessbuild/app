<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Contracts\Monitoring\DnsRecordResolver;
use Illuminate\Support\Facades\Process;
use Throwable;

final class NativeDnsRecordResolver implements DnsRecordResolver
{
    /**
     * Resolves DNS records with PHP's resolver.
     *
     * @param  DnsRecordSet  $sets  Validates the hostname and bounds the response.
     */
    public function __construct(private readonly DnsRecordSet $sets) {}

    /**
     * Runs the lookup in a child PHP process with a timeout, since the system resolver can't be interrupted otherwise.
     * Oversized answers and failures come back as null.
     */
    public function records(string $hostname, string $type, int $timeoutSeconds): ?array
    {
        $hostname = $this->sets->hostname($hostname);
        if ($hostname === null || ! in_array($type, DnsRecordSet::TYPES, true)) {
            return null;
        }

        try {
            // A child process makes the otherwise blocking system resolver interruptible.
            $result = Process::timeout(max(1, min(20, $timeoutSeconds)))->run([
                PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r',
                '$records = @dns_get_record($argv[1], constant($argv[2])); if ($records === false) { exit(1); } echo json_encode($records, JSON_THROW_ON_ERROR);',
                $hostname.'.', 'DNS_'.$type,
            ]);
            if (! $result->successful() || strlen($result->output()) > DnsRecordSet::RESPONSE_LIMIT) {
                return null;
            }
            $records = json_decode($result->output(), true, 16, JSON_THROW_ON_ERROR);

            return is_array($records) && array_is_list($records) ? $records : null;
        } catch (Throwable) {
            return null;
        }
    }
}
