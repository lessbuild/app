<?php

namespace App\Core\Data\Credentials;

use Carbon\CarbonImmutable;

/** Safe inventory metadata only; plaintext and stored credential hashes never cross this boundary. */
final readonly class WorkspaceCredential
{
    public function __construct(
        public string $key,
        public string $product,
        public string $productLabel,
        public string $type,
        public string $name,
        public string $scope,
        public string $status,
        public string $statusLabel,
        public ?string $prefix,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $lastUsedAt,
        public ?CarbonImmutable $expiresAt,
        public ?string $manageUrl,
    ) {}
}
