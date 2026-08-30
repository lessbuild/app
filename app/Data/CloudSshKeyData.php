<?php

namespace App\Data;

class CloudSshKeyData
{
    public function __construct(
        public readonly string $fingerprint,
        public readonly bool $created,
    ) {}
}
