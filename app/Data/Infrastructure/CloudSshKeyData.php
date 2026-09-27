<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

final readonly class CloudSshKeyData
{
    /**
     * Record the provider key reference and ownership of its creation.
     *
     * @param  string  $fingerprint  Provider fingerprint or opaque key ID used for subsequent deletion.
     * @param  bool  $created  True only when this request created the provider key, allowing safe cleanup.
     */
    public function __construct(
        public string $fingerprint,
        public bool $created,
    ) {}
}
