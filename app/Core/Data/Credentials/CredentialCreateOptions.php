<?php

namespace App\Core\Data\Credentials;

use Illuminate\Support\Collection;

/** @param Collection<int, CredentialCreateOption> $options */
final readonly class CredentialCreateOptions
{
    public function __construct(public Collection $options, public bool $available = true) {}
}
