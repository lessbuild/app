<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Models\Environment;
use App\Models\IngestReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/ingest/receipts/{receipt}: a delivery's processing status, for the environment of the key used. */
final class ShowIngestReceiptController
{
    public function __invoke(Request $request, string $receipt): JsonResponse
    {
        $environment = $request->attributes->get('ingest_environment');
        abort_unless($environment instanceof Environment, 401);
        $record = IngestReceipt::query()->where('environment_id', $environment->id)->findOrFail($receipt);

        return response()->json(['data' => [
            'id' => $record->id,
            'source' => $record->source->value,
            'status' => $record->status->value,
            'submitted' => $record->event_count,
            'accepted' => $record->accepted_count,
            'duplicates' => $record->duplicate_count,
            'attempts' => $record->attempt_count,
            'received_at' => $record->received_at->toISOString(),
            'last_received_at' => $record->last_received_at->toISOString(),
            'processed_at' => $record->processed_at?->toISOString(),
            'processing' => [
                'attempts' => $record->processing_attempts,
                'recoveries' => $record->recovery_count,
                'next_attempt_at' => $record->next_attempt_at?->toISOString(),
                'failed_at' => $record->failed_at?->toISOString(),
                'error_code' => $record->processingError() === null ? null : $record->last_error_code,
                'error_message' => $record->processingError(),
            ],
        ]])->header('Cache-Control', 'no-store, private');
    }
}
