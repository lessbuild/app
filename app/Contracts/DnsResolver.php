<?php

declare(strict_types=1);

namespace App\Contracts;

interface DnsResolver
{
    /**
     * Reads TXT records, used to verify that a customer controls a domain they've added.
     *
     * @param  string  $name
     * @return list<string> the TXT strings published at $name, or [] when there are none or the lookup fails
     */
    public function txtRecords(string $name): array;
}
