<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Projects\Contracts\DnsResolver;

final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    public array $records = [];

    public function txtRecords(string $name): array
    {
        return $this->records[$name] ?? [];
    }
}
