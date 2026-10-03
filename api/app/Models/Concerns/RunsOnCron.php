<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Cron;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A record that runs on a cron expression in a time zone (`cron_expression`, `timezone`), claimed once per minute
 * through a timestamp column so overlapping schedulers never run it twice.
 */
trait RunsOnCron
{
    /**
     * Get the column that records when the schedule last ran.
     *
     * @return string
     */
    abstract protected function lastRunColumn(): string;

    /**
     * Determine whether the schedule is due in the minute of a moment and hasn't run in that minute yet.
     *
     * @param  CarbonInterface  $now
     * @return bool
     */
    public function isDue(CarbonInterface $now): bool
    {
        $last = $this->getAttribute($this->lastRunColumn());

        return Cron::isDue($this->cron_expression, $this->timezone, $now)
            && ! ($last instanceof CarbonInterface && $last->gte($now->copy()->startOfMinute()));
    }

    /**
     * Claim this minute's run: records it as run unless another scheduler already has. Returns whether this caller
     * won the claim.
     *
     * @param  CarbonInterface  $now
     * @return bool
     */
    public function claim(CarbonInterface $now): bool
    {
        $column = $this->lastRunColumn();
        $claimed = static::query()->whereKey($this->getKey())
            ->where(fn ($query) => $query->whereNull($column)->orWhere($column, '<', $now->copy()->startOfMinute()))
            ->update([$column => $now]) === 1;
        if ($claimed) {
            $this->setAttribute($column, $now);
        }

        return $claimed;
    }

    /**
     * Get when the schedule next runs, in UTC, or null when it's off or invalid.
     *
     * @return CarbonImmutable|null
     */
    public function nextRunAt(): ?CarbonImmutable
    {
        return $this->is_enabled ? Cron::next($this->cron_expression, $this->timezone, now()) : null;
    }
}
