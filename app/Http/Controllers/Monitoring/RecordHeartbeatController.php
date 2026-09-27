<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\RecordHeartbeat;
use App\Http\Requests\Monitoring\StoreHeartbeatRequest;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/heartbeats/{heartbeat}: public contract from the old Monitor app. */
final class RecordHeartbeatController
{
    public function __invoke(StoreHeartbeatRequest $request, RecordHeartbeat $heartbeats): JsonResponse
    {
        $receipt = $heartbeats->handle(
            (int) $request->attributes->get('heartbeat_monitor_id'),
            (string) $request->attributes->get('heartbeat_token_hash'),
            strtolower((string) $request->validated('run_id')),
            (string) $request->validated('signal'),
        );

        return response()->json(['data' => $receipt])->header('Cache-Control', 'private, no-store');
    }
}
