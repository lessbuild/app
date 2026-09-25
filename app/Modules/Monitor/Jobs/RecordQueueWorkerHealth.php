<?php

namespace App\Modules\Monitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecordQueueWorkerHealth implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 180;

    public function __construct(public readonly string $queueName)
    {
        if (! in_array($queueName, ['telemetry', 'checks', 'alerts'], true)) {
            throw new InvalidArgumentException('The Monitor worker queue is not supported.');
        }

        $this->connection = $queueName;
        $this->queue = $queueName;
    }

    public function uniqueId(): string
    {
        return 'monitor-platform-health:'.app()->environment().':'.config('platform.products.monitor.host', 'default').':'.$this->queueName;
    }

    public function handle(): void
    {
        $now = now('UTC');

        DB::connection('monitor')->table('platform_queue_heartbeats')->upsert(
            [[
                'queue_name' => $this->queueName,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['queue_name'],
            ['last_seen_at', 'updated_at'],
        );
    }
}
