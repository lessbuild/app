<?php

namespace App\Modules\Monitor\Contracts;

interface TelemetryPayloadMapper
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function map(array $payload, string $signal): array;
}
