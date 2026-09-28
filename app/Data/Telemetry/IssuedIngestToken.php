<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

use App\Models\IngestToken;

final readonly class IssuedIngestToken
{
    /**
     * Create a new IssuedIngestToken instance.
     *
     * An ingest token that has just been created or rotated, with its secret.
     *
     * @param  IngestToken  $token  The stored token.
     * @param  string  $secret  The plain secret, shown once; only its hash is stored.
     */
    public function __construct(public IngestToken $token, public string $secret) {}
}
