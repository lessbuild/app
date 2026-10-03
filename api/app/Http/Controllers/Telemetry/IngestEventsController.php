<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Contracts\Telemetry\TelemetryIngestor;
use App\Data\Telemetry\IngestContext;
use App\Enums\IngestStatus;
use App\Http\Requests\Telemetry\StoreTelemetryRequest;
use App\Models\Environment;
use App\Models\IngestToken;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/ingest: public contract from the old Monitor app. 200 when stored, 202 when queued for processing. */
final class IngestEventsController
{
    /**
     * Store (or queues) a batch of JSON events for the environment the key belongs to, and returns what was accepted.
     *
     * @param  StoreTelemetryRequest  $request
     * @param  TelemetryIngestor  $ingestor
     * @return JsonResponse
     */
    public function __invoke(StoreTelemetryRequest $request, TelemetryIngestor $ingestor): JsonResponse
    {
        $environment = $request->attributes->get('ingest_environment');
        $token = $request->attributes->get('ingest_token');
        abort_unless($environment instanceof Environment && $token instanceof IngestToken, 401);
        $payload = $request->validated();
        $result = $ingestor->ingest($environment, (string) $payload['batch_id'], $payload['events'], new IngestContext(tokenId: $token->id));
        $completed = $result->status === IngestStatus::Completed;

        return response()->json(['data' => [
            'batch_id' => $result->batchId,
            'accepted' => $result->accepted,
            'duplicates' => $result->duplicates,
            'receipt_id' => $result->receiptId,
            'replayed' => $result->replayed,
            'status' => $result->status->value,
            'message' => $completed ? 'Telemetry batch accepted.' : 'Telemetry delivery retained for background processing.',
        ]], $completed ? 200 : 202)->header('Cache-Control', 'no-store, private');
    }
}
