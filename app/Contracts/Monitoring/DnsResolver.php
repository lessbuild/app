<?php

declare(strict_types=1);

namespace App\Contracts\Monitoring;

interface DnsResolver
{
    /**
     * Resolve a hostname to every address it points at, so checks can refuse private or reserved addresses before
     * connecting.
     *
     * @param  string  $hostname
     * @return list<string> All resolved A and AAAA addresses; an empty list means resolution failed.
     */
    public function addresses(string $hostname): array;
}
