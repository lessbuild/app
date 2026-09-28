<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\RecordQueueWorker;
use App\Http\Requests\Monitoring\StoreQueueWorkerRequest;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/queues/{queue}/workers: public contract from the old Monitor app. */
final class RecordQueueWorkerController
{
    /**
     * Record the worker heartbeat for the monitor the middleware authenticated and returns its receipt, never cached.
     *
     * @param  StoreQueueWorkerRequest  $request
     * @param  RecordQueueWorker  $workers
     * @return JsonResponse
     */
    public function __invoke(StoreQueueWorkerRequest $request, RecordQueueWorker $workers): JsonResponse
    {
        $receipt = $workers->handle((int) $request->attributes->get('queue_monitor_id'),
            (string) $request->attributes->get('queue_token_hash'), $request->details());

        return response()->json(['data' => $receipt])->header('Cache-Control', 'private, no-store');
    }
}
