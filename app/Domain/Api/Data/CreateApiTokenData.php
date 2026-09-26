<?php

declare(strict_types=1);

namespace App\Domain\Api\Data;

use App\Domain\Api\Enums\ApiScope;

final readonly class CreateApiTokenData
{
    /** @param list<ApiScope> $scopes */
    public function __construct(
        public string $name,
        public array $scopes,
        public ?int $expiresInDays,
    ) {}
}
