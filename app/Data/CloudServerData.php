<?php

namespace App\Data;

class CloudServerData
{
    public function __construct(
        public readonly int $identifier,
        public readonly string $name,
        public readonly string $region,
        public readonly string $size,
        public readonly string $image,
        public readonly ?string $publicIp = null,
        public readonly ?string $privateIp = null,
    ) {}
}
