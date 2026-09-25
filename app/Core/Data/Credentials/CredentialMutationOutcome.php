<?php

namespace App\Core\Data\Credentials;

use SensitiveParameter;

/** Secret material is private and excluded from serialization and debug inspection. */
final readonly class CredentialMutationOutcome
{
    public function __construct(
        public string $status,
        public string $credentialKey,
        public string $credentialType,
        public string $credentialName,
        #[SensitiveParameter] private ?string $secret = null,
    ) {}

    public function secretForImmediateResponse(): ?string
    {
        return $this->secret;
    }

    /** @return array{status: string, credentialKey: string, credentialType: string, credentialName: string} */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'credentialKey' => $this->credentialKey,
            'credentialType' => $this->credentialType,
            'credentialName' => $this->credentialName,
            'secret' => '[redacted]',
        ];
    }

    /** @return array{status: string, credentialKey: string, credentialType: string, credentialName: string} */
    public function __serialize(): array
    {
        return [
            'status' => $this->status,
            'credentialKey' => $this->credentialKey,
            'credentialType' => $this->credentialType,
            'credentialName' => $this->credentialName,
        ];
    }

    /** @param array{status?: string, credentialKey?: string, credentialType?: string, credentialName?: string} $data */
    public function __unserialize(array $data): void
    {
        $this->status = (string) ($data['status'] ?? 'secret_unavailable');
        $this->credentialKey = (string) ($data['credentialKey'] ?? '');
        $this->credentialType = (string) ($data['credentialType'] ?? '');
        $this->credentialName = (string) ($data['credentialName'] ?? '');
        $this->secret = null;
    }
}
