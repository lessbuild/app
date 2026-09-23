<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Contracts\DnsRecordResolver;
use Illuminate\Support\Facades\Process;
use Throwable;

final class NativeDnsRecordResolver implements DnsRecordResolver
{
    public function __construct(private readonly DnsRecordSet $sets) {}

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
