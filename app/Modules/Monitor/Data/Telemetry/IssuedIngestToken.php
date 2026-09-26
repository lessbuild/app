<?php

namespace App\Modules\Monitor\Data\Telemetry;

use App\Modules\Monitor\Models\IngestToken;

final readonly class IssuedIngestToken
{
    public function __construct(public IngestToken $token, public string $secret) {}
}
