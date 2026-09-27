<?php

declare(strict_types=1);

namespace App\Data\ApiTokens;

use App\Enums\ApiScope;

final readonly class CreateApiTokenData
{
    /** @param list<ApiScope> $scopes */
    public function __construct(
        public string $name,
        public array $scopes,
        public ?int $expiresInDays,
    ) {}
}
