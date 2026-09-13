<?php

namespace App\Data;

class CloudServerData
{
    public const READINESS_READY = 'ready';

    public const READINESS_NOT_READY = 'not_ready';

    public const READINESS_UNKNOWN = 'unknown';

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
     * @param  string|null  $providerStatus  Provider lifecycle value, retained for safe display only.
     * @param  'ready'|'not_ready'|'unknown'  $readiness  Whether the provider reports a usable running instance.
     */
    public function __construct(
        public readonly int|string $identifier,
        public readonly string $name,
        public readonly string $region,
        public readonly string $size,
        public readonly string $image,
        public readonly ?string $publicIp = null,
        public readonly ?string $privateIp = null,
        public readonly ?string $providerStatus = null,
        public readonly string $readiness = self::READINESS_UNKNOWN,
    ) {}
}
