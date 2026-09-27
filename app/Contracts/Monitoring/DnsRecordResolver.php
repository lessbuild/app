<?php

declare(strict_types=1);

namespace App\Contracts\Monitoring;

interface DnsRecordResolver
{
    /**
     * Looks up the records of one type (A, AAAA, CNAME, MX, TXT…) for a hostname, for DNS monitors that compare
     * answers with what's expected. Gives up after the timeout.
     *
     * @return list<array<string, mixed>>|null Null means lookup failure; an empty list is a completed lookup without records.
     */
    public function records(string $hostname, string $type, int $timeoutSeconds): ?array;
}
