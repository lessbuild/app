<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\RecordQueueSnapshot;
use App\Http\Requests\Monitoring\StoreQueueSnapshotRequest;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/queues/{queue}/snapshots: public contract from the old Monitor app. */
final class RecordQueueSnapshotController
{
    /**
     * Record the report for the monitor the middleware authenticated and returns its receipt, never cached.
     *
     * @param  StoreQueueSnapshotRequest  $request
     * @param  RecordQueueSnapshot  $snapshots
     * @return JsonResponse
     */
    public function __invoke(StoreQueueSnapshotRequest $request, RecordQueueSnapshot $snapshots): JsonResponse
    {
        $receipt = $snapshots->handle((int) $request->attributes->get('queue_monitor_id'),
            (string) $request->attributes->get('queue_token_hash'), $request->validated());

        return response()->json(['data' => $receipt])->header('Cache-Control', 'private, no-store');
    }
}
