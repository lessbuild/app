<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Contracts\Telemetry\TelemetryIngestor;
use App\Contracts\Telemetry\TelemetryPayloadMapper;
use App\Data\Telemetry\IngestContext;
use App\Enums\IngestSource;
use App\Http\Requests\Telemetry\StoreOtlpRequest;
use App\Models\Environment;
use App\Models\IngestToken;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/otlp/v1/{traces|logs|metrics}: OTLP/HTTP JSON, public contract from the old Monitor app. */
final class IngestOtlpController
{
    /**
     * Converts an OTLP export into events and stores them. Without an `X-Beacon-Batch` header, identical content is what
     * makes a resend a duplicate.
     *
     * @param  StoreOtlpRequest  $request
     * @param  string  $signal
     * @param  TelemetryPayloadMapper  $mapper
     * @param  TelemetryIngestor  $ingestor
     * @return JsonResponse
     */
    public function __invoke(StoreOtlpRequest $request, string $signal, TelemetryPayloadMapper $mapper, TelemetryIngestor $ingestor): JsonResponse
    {
        $environment = $request->attributes->get('ingest_environment');
        $token = $request->attributes->get('ingest_token');
        abort_unless($environment instanceof Environment && $token instanceof IngestToken, 401);
        $events = $mapper->map($request->validated(), $signal);
        $explicitBatch = $request->header('X-Beacon-Batch');
        $result = $ingestor->ingest($environment, $explicitBatch ?? 'otlp:'.$signal, $events, new IngestContext(
            source: IngestSource::from('otlp_'.$signal),
            explicitBatch: $explicitBatch !== null,
            tokenId: $token->id,
        ));

        $headers = [
            'X-Beacon-Accepted' => (string) $result->accepted,
            'X-Beacon-Duplicates' => (string) $result->duplicates,
            'Cache-Control' => 'no-store, private',
        ];
        if ($result->receiptId !== null) {
            $headers['X-Beacon-Receipt'] = $result->receiptId;
            $headers['X-Beacon-Replayed'] = $result->replayed ? 'true' : 'false';
            $headers['X-Beacon-Status'] = $result->status->value;
        }

        return response()->json((object) [], 200, $headers);
    }
}
