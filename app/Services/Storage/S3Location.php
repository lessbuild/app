<?php

declare(strict_types=1);

namespace App\Services\Storage;

/** Where an S3-compatible bucket is, and the keys to sign requests to it with. */
final readonly class S3Location
{
    /**
     * Create a new S3Location instance.
     *
     * @param  string  $endpoint  The HTTPS endpoint, e.g. https://s3.eu-central-1.amazonaws.com.
     * @param  string  $region  The signing region, e.g. eu-central-1 (auto for R2).
     * @param  string  $bucket
     * @param  string  $accessKey
     * @param  string  $secretKey
     */
    public function __construct(
        public string $endpoint,
        public string $region,
        public string $bucket,
        public string $accessKey,
        public string $secretKey,
    ) {}
}
