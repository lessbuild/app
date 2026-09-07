<?php

namespace App\Data;

class CloudServerData
{
    /**
     * Capture cloud instance fields in the common provider-independent format.
     *
     * @param  int|string  $identifier  Native provider instance ID.
     * @param  string  $name  Instance name or label supplied by the provider.
     * @param  string  $region  Provider region/location code, or an empty string when unavailable.
     * @param  string  $size  Provider size/plan code, or an empty string when unavailable.
     * @param  string  $image  Provider image identifier/name, or an empty string when unavailable.
     * @param  string|null  $publicIp  Assigned public address, if reported.
     * @param  string|null  $privateIp  Assigned private address, if reported.
     */
    public function __construct(
        public readonly int|string $identifier,
        public readonly string $name,
        public readonly string $region,
        public readonly string $size,
        public readonly string $image,
        public readonly ?string $publicIp = null,
        public readonly ?string $privateIp = null,
    ) {}
}
