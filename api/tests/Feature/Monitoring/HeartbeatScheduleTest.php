<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Monitor;
use App\Services\Monitoring\HeartbeatSchedule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HeartbeatScheduleTest extends TestCase
{
    use MonitoringHelpers;

    #[DataProvider('schedules')]
    public function test_calculates_the_next_fixed_schedule_in_utc(string $cron, string $zone, string $after, string $expected): void
    {
        $next = app(HeartbeatSchedule::class)->nextCron($cron, $zone, CarbonImmutable::parse($after));

        $this->assertSame($expected, $next->format('Y-m-d H:i:s T'));
    }

    /** @return array<string, list<mixed>> */
    public static function schedules(): array
    {
        return [
            'next minute' => ['* * * * *', 'UTC', '2026-09-21 10:00:00 UTC', '2026-09-21 10:01:00 UTC'],
            'after seconds' => ['*/5 * * * *', 'UTC', '2026-09-21 10:05:30 UTC', '2026-09-21 10:10:00 UTC'],
            'daily UTC' => ['0 2 * * *', 'UTC', '2026-09-21 01:00:00 UTC', '2026-09-21 02:00:00 UTC'],
            'summer New York' => ['0 2 * * *', 'America/New_York', '2026-09-21 00:00:00 UTC', '2026-09-21 06:00:00 UTC'],
            'winter New York' => ['0 2 * * *', 'America/New_York', '2026-01-21 00:00:00 UTC', '2026-01-21 07:00:00 UTC'],
            'leap day' => ['0 0 29 2 *', 'UTC', '2026-09-21 00:00:00 UTC', '2028-02-29 00:00:00 UTC'],
            'month end' => ['0 0 L * *', 'UTC', '2026-09-21 00:00:00 UTC', '2026-09-30 00:00:00 UTC'],
            'spring clock follows Laravel parser forward adjustment' => ['30 2 * * *', 'America/New_York', '2026-03-08 06:00:00 UTC', '2026-03-08 07:30:00 UTC'],
            'autumn repeats local time' => ['30 1 * * *', 'America/New_York', '2026-11-01 05:31:00 UTC', '2026-11-01 06:30:00 UTC'],
            'autumn next minute remains chronological' => ['* * * * *', 'America/New_York', '2026-11-01 05:59:30 UTC', '2026-11-01 06:00:00 UTC'],
        ];
    }

    public function test_interval_uses_elapsed_time_instead_of_rounding_to_a_cron_boundary(): void
    {
        $monitor = Monitor::factory()->heartbeat()->make(['heartbeat_interval_minutes' => 90, 'environment_id' => 1]);

        $next = app(HeartbeatSchedule::class)->next($monitor, CarbonImmutable::parse('2026-09-21 10:03:20 UTC'));

        $this->assertSame('2026-09-21 11:33:20', $next->format('Y-m-d H:i:s'));
    }

    #[DataProvider('invalidSchedules')]
    public function test_rejects_unsupported_or_impossible_cron_schedules(string $cron, string $zone): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(HeartbeatSchedule::class)->nextCron($cron, $zone, CarbonImmutable::parse('2026-09-21 UTC'));
    }

    /** @return array<string, list<mixed>> */
    public static function invalidSchedules(): array
    {
        return [
            'six fields' => ['* * * * * *', 'UTC'], 'macros' => ['@daily', 'UTC'],
            'empty' => ['', 'UTC'], 'bad minutes' => ['70 * * * *', 'UTC'],
            'unknown zone' => ['* * * * *', 'Not/AZone'], 'too long' => [str_repeat('* ', 55), 'UTC'],
        ];
    }
}
