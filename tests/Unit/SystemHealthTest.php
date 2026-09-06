<?php

namespace Tests\Unit;

use App\Services\OperationalDiagnostics;
use App\Services\SystemHealth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget('lessbuild:system-health-summary:v1');

        parent::tearDown();
    }

    public function test_dashboard_summary_is_cached_without_diagnostic_details(): void
    {
        Cache::forget('lessbuild:system-health-summary:v1');
        $this->mock(OperationalDiagnostics::class)
            ->shouldReceive('run')
            ->once()
            ->andReturn([
                ['name' => 'Application key', 'passed' => true, 'detail' => 'Configured'],
                ['name' => 'Failed queue jobs', 'passed' => false, 'detail' => 'private-diagnostic-detail'],
            ]);

        $health = app(SystemHealth::class);
        $first = $health->summary();
        $second = $health->summary();

        $this->assertSame($first, $second);
        $this->assertFalse($first['passed']);
        $this->assertSame(1, $first['passed_count']);
        $this->assertSame(2, $first['total']);
        $this->assertSame(['Failed queue jobs'], $first['failed_checks']);
        $this->assertStringNotContainsString('private-diagnostic-detail', json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function test_unexpected_diagnostic_errors_become_a_safe_degraded_snapshot(): void
    {
        Cache::forget('lessbuild:system-health-summary:v1');
        $this->mock(OperationalDiagnostics::class)
            ->shouldReceive('run')
            ->once()
            ->andThrow(new \RuntimeException('private-runtime-error'));

        $snapshot = app(SystemHealth::class)->fresh();

        $this->assertFalse($snapshot['passed']);
        $this->assertSame(0, $snapshot['passed_count']);
        $this->assertSame('Diagnostics runtime', $snapshot['checks'][0]['name']);
        $this->assertSame('Unable to complete the diagnostic snapshot', $snapshot['checks'][0]['detail']);
        $this->assertStringNotContainsString('private-runtime-error', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
