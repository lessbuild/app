<?php

namespace App\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ObservabilityContextFilters
{
    /** @var array<string, int> Supported investigation windows in hours. */
    public const WINDOWS = [
        '24h' => 24,
        '7d' => 168,
        '30d' => 720,
    ];

    public readonly CarbonImmutable $since;

    /**
     * Carry the finite, normalized window used by the environment evidence read.
     *
     * @param  '24h'|'7d'|'30d'  $window  User-facing investigation window.
     * @param  CarbonInterface  $now  Clock value captured at the HTTP boundary.
     */
    public function __construct(
        public readonly string $window,
        CarbonInterface $now,
    ) {
        $this->since = $now->copy()->subHours(self::WINDOWS[$window])->toImmutable();
    }

    /**
     * Build filters from the validated window while keeping the query independent of HTTP input.
     *
     * @param  '24h'|'7d'|'30d'  $window  Validated investigation window.
     */
    public static function fromWindow(string $window, ?CarbonInterface $now = null): self
    {
        return new self($window, $now ?? now());
    }
}
