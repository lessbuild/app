<?php

declare(strict_types=1);

namespace App\Domain\Projects\Contracts;

interface DnsResolver
{
    /** @return list<string> the TXT strings published at $name, or [] when there are none or the lookup fails */
    public function txtRecords(string $name): array;
}
