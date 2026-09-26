<?php

declare(strict_types=1);

namespace App\Domain\Api\Data;

use App\Domain\Api\Models\ApiToken;
use SensitiveParameter;

final readonly class NewApiToken
{
    public function __construct(
        public ApiToken $token,
        /** Shown to the creator once; only its hash is stored. */
        #[SensitiveParameter] public string $plainText,
    ) {}
}
