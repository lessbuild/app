<?php

namespace App\Modules\Analytics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class RecordQueueWorkerHealth implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 180;

    public function __construct()
    {
        $this->connection = 'analytics';
        $this->queue = 'analytics';
    }

    public function uniqueId(): string
    {
        return 'analytics-platform-health:'.app()->environment().':'.config('platform.products.analytics.host', 'default').':analytics';
    }

    public function handle(): void
    {
        $now = now('UTC');

        DB::connection('analytics')->table('platform_queue_heartbeats')->upsert(
            [[
                'queue_name' => 'analytics',
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['queue_name'],
            ['last_seen_at', 'updated_at'],
        );
    }
}
