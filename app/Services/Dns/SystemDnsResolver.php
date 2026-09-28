<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Contracts\DnsResolver;

/** Uses the server's resolver. Fine for ownership checks, which only need to see a record eventually. */
final class SystemDnsResolver implements DnsResolver
{
    /**
     * The TXT strings the system resolver returns for the name; none when the lookup fails.
     */
    public function txtRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            $txt = $record['txt'] ?? null;
            if (is_string($txt)) {
                $values[] = $txt;
            }
        }

        return $values;
    }
}
