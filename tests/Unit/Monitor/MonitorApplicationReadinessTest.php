<?php

namespace Tests\Unit\Monitor;

use App\Modules\Monitor\Services\MonitorApplicationReadiness;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MonitorApplicationReadinessTest extends TestCase
{
    public function test_database_readiness_is_separate_from_monitor_worker_liveness(): void
    {
        Schema::connection('monitor')->create('workspaces', fn (Blueprint $table) => $table->id());
        Schema::connection('monitor')->create('telemetry_events', fn (Blueprint $table) => $table->id());
        Schema::connection('monitor')->create('platform_queue_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('queue_name')->unique();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        $readiness = app(MonitorApplicationReadiness::class);

        $this->assertSame(['database' => true, 'background_processing' => false], $readiness->checks());

        foreach (['telemetry', 'checks', 'alerts'] as $queue) {
            DB::connection('monitor')->table('platform_queue_heartbeats')->insert([
                'queue_name' => $queue,
                'last_seen_at' => now('UTC'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        }

        $this->assertSame(['database' => true, 'background_processing' => true], $readiness->checks());

        DB::connection('monitor')->table('platform_queue_heartbeats')
            ->where('queue_name', 'alerts')
            ->update(['last_seen_at' => now('UTC')->subMinutes(10)]);

        $this->assertFalse($readiness->checks()['background_processing']);
    }
}
