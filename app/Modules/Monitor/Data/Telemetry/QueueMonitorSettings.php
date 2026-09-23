<?php

namespace App\Modules\Monitor\Data\Telemetry;

final class QueueMonitorSettings
{
    public const DEFAULTS = [
        'report_timeout_seconds' => 180, 'worker_timeout_seconds' => 120, 'minimum_workers' => 1,
        'max_pending' => 1000, 'max_delayed' => null, 'max_reserved' => null, 'max_failed' => 0,
        'max_oldest_wait_seconds' => 300, 'max_runtime_seconds' => 900,
    ];

    public const LIMITS = [
        'report_timeout_seconds' => [60, 3600], 'worker_timeout_seconds' => [30, 3600], 'minimum_workers' => [0, 100],
        'max_pending' => [0, 1000000000], 'max_delayed' => [0, 1000000000],
        'max_reserved' => [0, 1000000000], 'max_failed' => [0, 1000000000],
        'max_oldest_wait_seconds' => [1, 604800], 'max_runtime_seconds' => [1, 604800],
    ];

    public const METRICS = ['pending', 'delayed', 'reserved', 'failed', 'oldest_wait_seconds'];

    public const LABELS = [
        'report_timeout_seconds' => 'Queue report timeout (seconds)', 'worker_timeout_seconds' => 'Worker heartbeat timeout (seconds)',
        'minimum_workers' => 'Minimum live workers (0 disables capacity alerts)',
        'max_pending' => 'Maximum ready jobs', 'max_delayed' => 'Maximum delayed jobs',
        'max_reserved' => 'Maximum reserved / in-flight jobs', 'max_failed' => 'Maximum failed / dead-letter jobs',
        'max_oldest_wait_seconds' => 'Maximum oldest ready-job wait (seconds)', 'max_runtime_seconds' => 'Maximum busy-job duration (seconds)',
    ];

    /** @param array<string, mixed> $settings
     * @return array<string, int|null>
     */
    public static function normalize(array $settings): array
    {
        $normalized = [];
        foreach (self::LIMITS as $key => $limits) {
            $normalized[$key] = isset($settings[$key]) ? (int) $settings[$key] : null;
        }

        return $normalized;
    }
}
