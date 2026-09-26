<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Monitor;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use InvalidArgumentException;

final class HeartbeatSchedule
{
    public function next(Monitor $monitor, CarbonImmutable $after): CarbonImmutable
    {
        if ($monitor->heartbeat_schedule === 'interval' && $monitor->heartbeat_interval_minutes >= 1
            && $monitor->heartbeat_interval_minutes <= 43200) {
            return $after->addMinutes($monitor->heartbeat_interval_minutes);
        }
        if ($monitor->heartbeat_schedule !== 'cron') {
            throw new InvalidArgumentException('Invalid heartbeat schedule.');
        }

        return $this->nextCron($monitor->heartbeat_cron ?? '', $monitor->heartbeat_timezone ?? '', $after);
    }

    /** Uses Laravel's installed cron parser, including its DST transition behavior. */
    public function nextCron(string $expression, string $timezone, CarbonImmutable $after): CarbonImmutable
    {
        $expression = trim($expression);
        if (strlen($expression) > 100 || count(preg_split('/\\s+/', $expression)) !== 5
            || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Use a five-field cron expression and an IANA timezone.');
        }
        $cron = (new CronExpression($expression))->setMaxIterationCount(2400);

        return CarbonImmutable::instance($cron->getNextRunDate($after, 0, false, $timezone))->utc();
    }

    public function reset(Monitor $monitor, CarbonImmutable $now): void
    {
        $due = $monitor->enabled ? $this->next($monitor, $now) : null;
        $monitor->forceFill(['heartbeat_sequence' => null, 'heartbeat_due_at' => $due,
            'heartbeat_received_at' => null, 'heartbeat_succeeded_at' => null,
            'next_check_at' => $due?->addMinutes($monitor->heartbeat_grace_minutes)]);
    }
}
