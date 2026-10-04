<?php

declare(strict_types=1);

namespace App\Data\Monitoring;

use App\Models\Monitor;

final readonly class MonitorSummary
{
    /**
     * Create a new MonitorSummary instance.
     *
     * A monitor as lists show it.
     *
     * @param  int  $id
     * @param  string  $name
     * @param  string  $type  http, dns, tls, tcp, flow, heartbeat or queue.
     * @param  string  $typeLabel  Such as "HTTP uptime".
     * @param  string  $environment
     * @param  string  $target  What it checks, never a secret (a URL's host, a hostname, a queue label…).
     * @param  string  $health  Up, Down, Paused, Pending, Archived… (English, for its tone).
     * @param  string  $healthLabel  The same, in the person's language.
     * @param  string|null  $checkedAt  ISO 8601.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public string $typeLabel,
        public string $environment,
        public string $target,
        public string $health,
        public string $healthLabel,
        public ?string $checkedAt,
    ) {}

    /**
     * Describe a monitor (with its environment loaded).
     *
     * @param  Monitor  $monitor
     * @return self
     */
    public static function from(Monitor $monitor): self
    {
        $health = $monitor->healthLabel();

        return new self(
            id: $monitor->id,
            name: $monitor->name,
            type: $monitor->type,
            typeLabel: $monitor->typeLabel(),
            environment: $monitor->environment->name,
            target: $monitor->targetLabel(),
            health: $health,
            healthLabel: __($health),
            checkedAt: $monitor->checked_at?->toIso8601String(),
        );
    }
}
