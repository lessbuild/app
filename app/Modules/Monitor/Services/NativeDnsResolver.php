<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Contracts\DnsResolver;

final class NativeDnsResolver implements DnsResolver
{
    public function addresses(string $hostname): array
    {
        return $this->resolve($hostname, []);
    }

    /** @param list<string> $visited
     * @return list<string>
     */
    private function resolve(string $hostname, array $visited): array
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        if (in_array($hostname, $visited, true) || count($visited) >= 5) {
            return [];
        }
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA | DNS_CNAME);
        if ($records === false || $records === []) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            } elseif (isset($record['target'])) {
                $resolved = $this->resolve($record['target'], [...$visited, $hostname]);
                if ($resolved === []) {
                    return [];
                }
                $addresses = [...$addresses, ...$resolved];
            }
        }

        return array_values(array_unique($addresses));
    }
}
