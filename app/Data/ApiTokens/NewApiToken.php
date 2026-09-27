<?php

declare(strict_types=1);

namespace App\Data\ApiTokens;

use App\Models\ApiToken;
use SensitiveParameter;

final readonly class NewApiToken
{
    public function __construct(
        public ApiToken $token,
        /** Shown to the creator once; only its hash is stored. */
        #[SensitiveParameter] public string $plainText,
    ) {}
}
