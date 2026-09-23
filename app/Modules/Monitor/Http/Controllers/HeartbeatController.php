<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\StoreHeartbeatRequest;
use App\Modules\Monitor\Http\Resources\HeartbeatReceiptResource;
use App\Modules\Monitor\Services\RecordHeartbeat;
use Illuminate\Http\JsonResponse;

class HeartbeatController extends Controller
{
    public function store(StoreHeartbeatRequest $request, RecordHeartbeat $heartbeats): JsonResponse
    {
        $receipt = $heartbeats->receive(
            (int) $request->attributes->get('heartbeat_monitor_id'),
            $request->attributes->get('heartbeat_token_hash'),
            strtolower($request->validated('run_id')),
            $request->validated('signal'),
        );

        return (new HeartbeatReceiptResource($receipt))->response()->header('Cache-Control', 'private, no-store');
    }
}
