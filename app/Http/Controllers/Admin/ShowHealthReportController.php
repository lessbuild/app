<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\Admin\HealthCheck;
use App\Data\Admin\QueueState;
use App\Services\Admin\SystemHealth;
use Illuminate\Http\JsonResponse;

final class ShowHealthReportController
{
    /**
     * Download the health checks and queue backlogs as JSON, with an overall status: ready when every check and queue
     * is healthy.
     *
     * @param  SystemHealth  $health
     * @return JsonResponse
     */
    public function __invoke(SystemHealth $health): JsonResponse
    {
        $checks = $health->checks();
        $queues = $health->queues();
        $ready = ! in_array(false, [...array_map(fn (HealthCheck $check): bool => $check->passed, $checks), ...array_map(fn (QueueState $queue): bool => $queue->healthy, $queues)], true);

        return response()->json([
            'status' => $ready ? 'ready' : 'degraded',
            'generated_at' => now()->toIso8601String(),
            'checks' => array_map(fn (HealthCheck $check): array => $check->toArray(), $checks),
            'queues' => array_map(fn (QueueState $queue): array => $queue->toArray(), $queues),
        ], headers: [
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'attachment; filename="platform-health-'.now()->utc()->format('Ymd-His').'.json"',
        ]);
    }
}
