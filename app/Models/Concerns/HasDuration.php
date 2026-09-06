<?php

namespace App\Models\Concerns;

trait HasDuration
{
    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->finished_at || $this->finished_at->isBefore($this->started_at)) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }

    public function durationLabel(): ?string
    {
        $seconds = $this->durationSeconds();

        return $seconds === null ? null : self::formatDuration($seconds);
    }

    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('A duration cannot be negative.');
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;
        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($remainingSeconds > 0 || $parts === []) {
            $parts[] = "{$remainingSeconds}s";
        }

        return implode(' ', $parts);
    }
}
