<?php

namespace App\Modules\Monitor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MonitorApplicationReadiness
{
    /** @var list<string> */
    private const QUEUES = ['telemetry', 'checks', 'alerts'];

    /** @return array{database: bool, background_processing: bool} */
    public function checks(): array
    {
        return [
            'database' => $this->databaseReady(),
            'background_processing' => $this->workersReady(),
        ];
    }

    public function isReady(): bool
    {
        return collect($this->checks())->every(static fn (bool $ready): bool => $ready);
    }

    private function databaseReady(): bool
    {
        try {
            $ready = Schema::connection('monitor')->hasTable('workspaces')
                && Schema::connection('monitor')->hasTable('telemetry_events');
            if (! $ready) {
                return false;
            }

            DB::connection('monitor')->select('select 1');

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function workersReady(): bool
    {
        try {
            if (! Schema::connection('monitor')->hasTable('platform_queue_heartbeats')) {
                return false;
            }

            $freshAfter = now('UTC')->subSeconds(max(120, (int) config('monitor.beacon.platform_status.worker_stale_after_seconds', 180)));

            foreach (self::QUEUES as $queue) {
                $fresh = DB::connection('monitor')->table('platform_queue_heartbeats')
                    ->where('queue_name', $queue)
                    ->where('last_seen_at', '>=', $freshAfter)
                    ->exists();

                if (! $fresh) {
                    return false;
                }
            }

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
