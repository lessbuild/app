<?php

namespace App\Models\Concerns;

use App\Presenters\DurationPresenter;

trait HasDuration
{
    /** @return int|null Completed elapsed seconds, or null for incomplete or inconsistent timestamps. */
    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->finished_at || $this->finished_at->isBefore($this->started_at)) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }

    /** @return string|null A compact duration label, or null until a valid completion is recorded. */
    public function durationLabel(): ?string
    {
        $seconds = $this->durationSeconds();

        return $seconds === null ? null : self::formatDuration($seconds);
    }

    /**
     * @param  int  $seconds  A nonnegative elapsed duration.
     * @return string A compact label shared by build and command history.
     */
    public static function formatDuration(int $seconds): string
    {
        return DurationPresenter::format($seconds);
    }
}
