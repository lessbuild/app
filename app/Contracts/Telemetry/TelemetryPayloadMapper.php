<?php

declare(strict_types=1);

namespace App\Contracts\Telemetry;

interface TelemetryPayloadMapper
{
    /**
     * Converts one OTLP/JSON export for a signal (`traces`, `logs` or `metrics`) into our flat event rows, ready for the
     * ingestor. Unknown signals throw.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function map(array $payload, string $signal): array;
}
