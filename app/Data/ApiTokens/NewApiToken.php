<?php

declare(strict_types=1);

namespace App\Data\ApiTokens;

use App\Models\ApiToken;
use SensitiveParameter;

final readonly class NewApiToken
{
    /**
     * Create a new NewApiToken instance.
     *
     * A token that has just been created, with the one chance to see its secret.
     *
     * @param  ApiToken  $token  The stored token.
     * @param  string  $plainText  Shown to the creator once; only its hash is stored.
     */
    public function __construct(
        public ApiToken $token,
        #[SensitiveParameter] public string $plainText,
    ) {}
}
