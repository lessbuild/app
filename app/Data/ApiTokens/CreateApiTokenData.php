<?php

declare(strict_types=1);

namespace App\Data\ApiTokens;

use App\Enums\ApiScope;

final readonly class CreateApiTokenData
{
    /**
     * Create a new CreateApiTokenData instance.
     *
     * A token someone wants to create.
     *
     * @param  string  $name  A name to recognise it by, such as the CI system using it.
     * @param  list<ApiScope>  $scopes
     * @param  ?int  $expiresInDays  How long it lasts; null for a token that doesn't expire.
     */
    public function __construct(
        public string $name,
        public array $scopes,
        public ?int $expiresInDays,
    ) {}
}
