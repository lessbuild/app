<?php

namespace App\Core\Data\Monitor;

use JsonSerializable;
use SensitiveParameter;

final class MonitorMutationResult implements JsonSerializable
{
    private ?string $secret;

    public function __construct(
        public readonly bool $succeeded,
        public readonly string $status,
        public readonly ?string $reference = null,
        #[SensitiveParameter] ?string $oneTimeSecret = null,
    ) {
        $this->secret = $oneTimeSecret;
    }

    public function takeOneTimeSecret(): ?string
    {
        return $this->secret;
    }

    public function jsonSerialize(): array
    {
        return ['succeeded' => $this->succeeded, 'status' => $this->status, 'reference' => $this->reference];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return array{succeeded: bool, status: string, reference: string|null} */
    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    /** @param array{succeeded?: bool, status?: string, reference?: string|null} $data */
    public function __unserialize(array $data): void
    {
        $this->succeeded = (bool) ($data['succeeded'] ?? false);
        $this->status = (string) ($data['status'] ?? 'secret_unavailable');
        $this->reference = isset($data['reference']) ? (string) $data['reference'] : null;
        $this->secret = null;
    }
}
