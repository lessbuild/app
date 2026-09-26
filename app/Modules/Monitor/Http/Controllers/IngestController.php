<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Contracts\TelemetryIngestor;
use App\Modules\Monitor\Data\Telemetry\IngestContext;
use App\Modules\Monitor\Http\Requests\StoreTelemetryRequest;
use App\Modules\Monitor\Http\Resources\IngestBatchResource;
use App\Modules\Monitor\Models\Environment;

class IngestController extends Controller
{
    public function __construct(private readonly TelemetryIngestor $ingestor) {}

    public function store(StoreTelemetryRequest $request): IngestBatchResource
    {
        /** @var Environment $environment */
        $environment = $request->attributes->get('ingest_environment');
        $payload = $request->validated();

        $result = $this->ingestor->ingest(
            $environment, $payload['batch_id'], $payload['events'],
            new IngestContext(tokenId: $request->attributes->get('ingest_token')->id),
        );

        return new IngestBatchResource($result);
    }
}
