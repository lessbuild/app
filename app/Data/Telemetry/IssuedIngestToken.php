<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

use App\Models\IngestToken;

final readonly class IssuedIngestToken
{
    public function __construct(public IngestToken $token, public string $secret) {}
}
