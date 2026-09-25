<?php

namespace App\Core\Data\Credentials;

use Illuminate\Support\Collection;

/** @param Collection<int, WorkspaceCredential> $credentials */
final readonly class WorkspaceCredentialSnapshot
{
    public function __construct(
        public Collection $credentials,
        public bool $available = true,
    ) {}
}
