<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\StoreQueueSnapshotRequest;
use App\Modules\Monitor\Http\Resources\QueueSignalReceiptResource;
use App\Modules\Monitor\Services\RecordQueueSnapshot;
use Illuminate\Http\JsonResponse;

class QueueSnapshotController extends Controller
{
    public function store(StoreQueueSnapshotRequest $request, RecordQueueSnapshot $snapshots): JsonResponse
    {
        $receipt = $snapshots->record((int) $request->attributes->get('queue_monitor_id'),
            $request->attributes->get('queue_token_hash'), $request->validated());

        return (new QueueSignalReceiptResource($receipt))->response()->header('Cache-Control', 'private, no-store');
    }
}
