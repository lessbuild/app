<?php

namespace App\Core\Data\Credentials;

final readonly class CredentialCreateOption
{
    /** @param list<array{key: string, label: string}> $scopes */
    public function __construct(
        public string $product,
        public string $type,
        public string $label,
        public string $targetKey,
        public string $scope,
        public array $scopes = [],
        public bool $requiresName = true,
        public bool $supportsExpiry = false,
        public array $permissions = [],
        public ?int $defaultExpiryDays = null,
    ) {}
}
