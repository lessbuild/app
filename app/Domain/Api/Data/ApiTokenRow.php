<?php

declare(strict_types=1);

namespace App\Domain\Api\Data;

use App\Domain\Api\Enums\ApiScope;
use Carbon\CarbonImmutable;

final readonly class ApiTokenRow
{
    /** @param list<ApiScope> $scopes */
    public function __construct(
        public int $id,
        public string $name,
        public string $owner,
        public bool $ownedByViewer,
        public array $scopes,
        public ?CarbonImmutable $lastUsedAt,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $createdAt,
    ) {}
}
