<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Contracts\TelemetryIngestor;
use App\Modules\Monitor\Contracts\TelemetryPayloadMapper;
use App\Modules\Monitor\Data\Telemetry\IngestContext;
use App\Modules\Monitor\Data\Telemetry\IngestSource;
use App\Modules\Monitor\Http\Requests\StoreOtlpRequest;
use App\Modules\Monitor\Models\Environment;
use Illuminate\Http\JsonResponse;

class OtlpIngestController extends Controller
{
    public function __construct(
        private readonly TelemetryPayloadMapper $mapper,
        private readonly TelemetryIngestor $ingestor,
    ) {}

    public function store(StoreOtlpRequest $request, string $signal): JsonResponse
    {
        /** @var Environment $environment */
        $environment = $request->attributes->get('ingest_environment');
        $payload = $request->validated();
        $events = $this->mapper->map($payload, $signal);
        $explicitBatch = $request->header('X-Beacon-Batch');
        $batchId = $explicitBatch ?? 'otlp:'.$signal;
        $result = $this->ingestor->ingest($environment, $batchId, $events, new IngestContext(
            source: IngestSource::from('otlp_'.$signal),
            explicitBatch: $explicitBatch !== null,
            tokenId: $request->attributes->get('ingest_token')->id,
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
