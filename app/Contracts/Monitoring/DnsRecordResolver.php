<?php

declare(strict_types=1);

namespace App\Contracts\Monitoring;

interface DnsRecordResolver
{
    /** @return list<array<string, mixed>>|null Null means lookup failure; an empty list is a completed lookup without records. */
    public function records(string $hostname, string $type, int $timeoutSeconds): ?array;
}
