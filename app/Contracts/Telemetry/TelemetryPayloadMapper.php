<?php

declare(strict_types=1);

namespace App\Contracts\Telemetry;

interface TelemetryPayloadMapper
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function map(array $payload, string $signal): array;
}
