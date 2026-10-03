<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\Account;
use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Queries\Telemetry\TelemetrySummaryQuery;
use Carbon\CarbonImmutable;
use Database\Factories\MonitorFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TelemetrySummaryTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private string $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'));
        $project = Project::factory()->withServices(['monitoring'])->create();
        $this->account = $project->account;
        $this->environment = MonitorFactory::environment($project);
    }

    /**
     * Events at a range's start count; one microsecond earlier belongs to the range before.
     */
    #[DataProvider('ranges')]
    public function test_ranges_split_current_previous_and_future_events(string $range, string $start, string $previousStart, int $buckets): void
    {
        $this->event(['occurred_at' => $start]);
        $this->event(['occurred_at' => now()]);
        $this->event(['occurred_at' => $previousStart]);
        $this->event(['occurred_at' => CarbonImmutable::parse($start, 'UTC')->subMicrosecond()]);
        $this->event(['occurred_at' => CarbonImmutable::parse($previousStart, 'UTC')->subMicrosecond()]);
        $this->event(['occurred_at' => now()->addMicrosecond()]);

        $summary = app(TelemetrySummaryQuery::class)->handle($this->account, $range);

        $this->assertSame(2, $summary['eventCount']);
        $this->assertSame(2, $summary['previous']['eventCount']);
        $this->assertCount($buckets, $summary['trend']);
        $this->assertSame(1, $summary['trend'][0]['eventCount']);
        $this->assertSame(1, $summary['trend'][$buckets - 1]['eventCount']);
        $this->assertSame($start, $summary['from']->format('Y-m-d H:i:s'));
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function ranges(): array
    {
        return [
            'day' => ['24h', '2026-09-19 12:00:00', '2026-09-18 12:00:00', 12],
            'week' => ['7d', '2026-09-13 12:00:00', '2026-09-06 12:00:00', 7],
            'month' => ['30d', '2026-08-21 12:00:00', '2026-07-22 12:00:00', 15],
        ];
    }

    public function test_duration_and_error_rate_count_only_requests(): void
    {
        $this->event(['duration_ms' => 100, 'status_code' => 200]);
        $this->event(['duration_ms' => 0, 'status_code' => 503]);
        $this->event(['duration_ms' => null, 'status_code' => 200, 'severity' => 'error']);
        $this->event(['type' => 'query', 'duration_ms' => 5000]);
        $this->event(['type' => 'exception', 'severity' => 'critical', 'duration_ms' => null, 'status_code' => null]);
        $this->event(['type' => 'legacy-thing', 'duration_ms' => null]);

        $summary = app(TelemetrySummaryQuery::class)->handle($this->account, '24h');

        $this->assertSame(6, $summary['eventCount']);
        $this->assertSame(3, $summary['requestCount']);
        $this->assertSame(2, $summary['timedRequestCount']);
        $this->assertEqualsWithDelta(50.0, $summary['averageDuration'], 0.001);
        $this->assertEqualsWithDelta(66.667, $summary['requestErrorRate'], 0.001);
        $this->assertSame(['request' => 3, 'query' => 1, 'job' => 0, 'exception' => 1, 'log' => 0, 'metric' => 0, 'other' => 1], $summary['eventBreakdown']);
    }

    public function test_changes_compare_equal_ranges_and_need_a_baseline(): void
    {
        $this->event(['occurred_at' => now()->subHours(30), 'duration_ms' => 100, 'status_code' => 500]);
        $this->event(['occurred_at' => now()->subHours(30), 'duration_ms' => 100, 'status_code' => 200]);
        $this->event(['duration_ms' => 150, 'status_code' => 200]);

        $summary = app(TelemetrySummaryQuery::class)->handle($this->account, '24h');

        $this->assertSame(-50.0, $summary['changes']['events']);
        $this->assertSame(50.0, $summary['changes']['duration']);
        $this->assertSame(-50.0, $summary['changes']['errorRate']);

        TelemetryEvent::query()->where('occurred_at', '<', now()->subDay())->delete();
        $this->assertNull(app(TelemetrySummaryQuery::class)->handle($this->account, '24h')['changes']['events']);
    }

    public function test_other_accounts_dont_count(): void
    {
        TelemetryEvent::factory()->create(['occurred_at' => now()]);

        $this->assertSame(0, app(TelemetrySummaryQuery::class)->handle($this->account, '24h')['eventCount']);
    }

    /** @param array<string, mixed> $attributes */
    private function event(array $attributes = []): void
    {
        TelemetryEvent::factory()->create(['environment_id' => $this->environment, 'occurred_at' => now()->subHour(), ...$attributes]);
    }
}
