<?php

namespace Tests\Unit\Analytics;

use App\Modules\Analytics\Services\AnalyticsApplicationReadiness;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AnalyticsApplicationReadinessTest extends TestCase
{
    public function test_analytics_reports_worker_staleness_independently_from_database_and_cache(): void
    {
        Schema::connection('analytics')->create('sites', fn (Blueprint $table) => $table->id());
        Schema::connection('analytics')->create('ingestion_batches', fn (Blueprint $table) => $table->id());
        Schema::connection('analytics')->create('platform_queue_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('queue_name')->unique();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        $readiness = app(AnalyticsApplicationReadiness::class);
        $checks = $readiness->checks();

        $this->assertTrue($checks['database']);
        $this->assertTrue($checks['cache']);
        $this->assertFalse($checks['background_processing']);

        DB::connection('analytics')->table('platform_queue_heartbeats')->insert([
            'queue_name' => 'analytics',
            'last_seen_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->assertTrue($readiness->checks()['background_processing']);
    }
}
