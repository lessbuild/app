<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SystemHealth
{
    private const CACHE_KEY = 'lessbuild:system-health-summary:v1';

    /**
     * Bind operational diagnostics for the authenticated health summary.
     *
     * @param  OperationalDiagnostics  $diagnostics  Supplies readiness and infrastructure diagnostic checks.
     */
    public function __construct(private readonly OperationalDiagnostics $diagnostics) {}

    /**
     * @return array{passed: bool, passed_count: int, total: int, failed_checks: list<string>, checked_at: string}
     */
    public function summary(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            max(10, (int) config('lessbuild.diagnostics.dashboard_cache_seconds')),
            fn (): array => $this->summaryFromSnapshot($this->fresh()),
        );
    }

    /**
     * @return array{
     *     checks: list<array{name: string, passed: bool, detail: string}>,
     *     passed: bool,
     *     passed_count: int,
     *     checked_at: Carbon
     * }
     */
    public function fresh(): array
    {
        try {
            $checks = $this->diagnostics->run();
        } catch (Throwable $exception) {
            report($exception);
            $checks = [[
                'name' => 'Diagnostics runtime',
                'passed' => false,
                'detail' => 'Unable to complete the diagnostic snapshot',
            ]];
        }

        $passedCount = collect($checks)->where('passed', true)->count();
        $snapshot = [
            'checks' => $checks,
            'passed' => $passedCount === count($checks),
            'passed_count' => $passedCount,
            'checked_at' => now(),
        ];

        Cache::put(
            self::CACHE_KEY,
            $this->summaryFromSnapshot($snapshot),
            max(10, (int) config('lessbuild.diagnostics.dashboard_cache_seconds')),
        );

        return $snapshot;
    }

    /**
     * @param  array{
     *     checks: list<array{name: string, passed: bool, detail: string}>,
     *     passed: bool,
     *     passed_count: int,
     *     checked_at: Carbon
     * }  $snapshot
     * @return array{passed: bool, passed_count: int, total: int, failed_checks: list<string>, checked_at: string}
     */
    private function summaryFromSnapshot(array $snapshot): array
    {
        return [
            'passed' => $snapshot['passed'],
            'passed_count' => $snapshot['passed_count'],
            'total' => count($snapshot['checks']),
            'failed_checks' => collect($snapshot['checks'])
                ->where('passed', false)
                ->pluck('name')
                ->take(3)
                ->values()
                ->all(),
            'checked_at' => $snapshot['checked_at']->toIso8601String(),
        ];
    }
}
