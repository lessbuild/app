<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

final readonly class CloudServerData
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
        public int|string $identifier,
        public string $name,
        public string $region,
        public string $size,
        public string $image,
        public ?string $publicIp = null,
        public ?string $privateIp = null,
        public ?string $providerStatus = null,
        public string $readiness = self::READINESS_UNKNOWN,
    ) {}
}
