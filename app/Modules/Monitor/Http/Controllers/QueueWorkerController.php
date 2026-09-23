<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\StoreQueueWorkerRequest;
use App\Modules\Monitor\Http\Resources\QueueSignalReceiptResource;
use App\Modules\Monitor\Services\RecordQueueWorker;
use Illuminate\Http\JsonResponse;

class QueueWorkerController extends Controller
{
    public function store(StoreQueueWorkerRequest $request, RecordQueueWorker $workers): JsonResponse
    {
        $receipt = $workers->record((int) $request->attributes->get('queue_monitor_id'),
            $request->attributes->get('queue_token_hash'), $request->validated());

        return (new QueueSignalReceiptResource($receipt))->response()->header('Cache-Control', 'private, no-store');
    }
}
