<?php

namespace App\Core\Data\Credentials;

final readonly class CredentialMutationCommand
{
    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $canonicalProjectIds
     */
    public function __construct(
        public string $action,
        public string $product,
        public string $credentialType,
        public ?string $credentialKey,
        public ?string $targetKey,
        public ?string $name,
        public ?int $expiresInDays,
        public array $permissions,
        public array $canonicalProjectIds,
        public string $operationId,
        public string $inputHash,
        public bool $replayOnly = false,
    ) {}
}
