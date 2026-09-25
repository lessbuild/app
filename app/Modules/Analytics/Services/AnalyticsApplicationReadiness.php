<?php

namespace App\Modules\Analytics\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AnalyticsApplicationReadiness
{
    /** @return array{database: bool, cache: bool, background_processing: bool} */
    public function checks(): array
    {
        return [
            'database' => $this->databaseReady(),
            'cache' => $this->cacheReady(),
            'background_processing' => $this->workerReady(),
        ];
    }

    private function databaseReady(): bool
    {
        try {
            if (! Schema::connection('analytics')->hasTable('sites')
                || ! Schema::connection('analytics')->hasTable('ingestion_batches')) {
                return false;
            }

            DB::connection('analytics')->select('select 1');

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function cacheReady(): bool
    {
        try {
            Cache::store()->get('analytics-readiness');

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function workerReady(): bool
    {
        try {
            if (! Schema::connection('analytics')->hasTable('platform_queue_heartbeats')) {
                return false;
            }

            $freshAfter = now('UTC')->subSeconds(max(120, (int) config('analytics.platform_status.worker_stale_after_seconds', 180)));

            return DB::connection('analytics')->table('platform_queue_heartbeats')
                ->where('queue_name', 'analytics')
                ->where('last_seen_at', '>=', $freshAfter)
                ->exists();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
