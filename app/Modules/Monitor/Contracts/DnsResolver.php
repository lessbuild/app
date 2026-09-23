<?php

namespace App\Modules\Monitor\Contracts;

interface DnsResolver
{
    /** @return list<string> All resolved A and AAAA addresses; an empty list means resolution failed. */
    public function addresses(string $hostname): array;
}
