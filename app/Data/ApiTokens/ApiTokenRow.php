<?php

declare(strict_types=1);

namespace App\Data\ApiTokens;

use App\Enums\ApiScope;
use Carbon\CarbonImmutable;

final readonly class ApiTokenRow
{
    /**
     * One token on the API tokens page.
     *
     * @param  int  $id  The token's ID, used to revoke it.
     * @param  string  $name  The name its creator gave it.
     * @param  string  $owner  The name of the person who created it; tokens act as them.
     * @param  bool  $ownedByViewer  Whether the viewer created it.
     * @param  list<ApiScope>  $scopes
     * @param  ?CarbonImmutable  $lastUsedAt  When it last authenticated a request.
     * @param  ?CarbonImmutable  $expiresAt  When it stops working; null for tokens that don't expire.
     * @param  ?CarbonImmutable  $createdAt  When it was created.
     */
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
