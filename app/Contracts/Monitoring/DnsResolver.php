<?php

declare(strict_types=1);

namespace App\Contracts\Monitoring;

interface DnsResolver
{
    /** @return list<string> All resolved A and AAAA addresses; an empty list means resolution failed. */
    public function addresses(string $hostname): array;
}
