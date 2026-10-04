<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use App\Models\AnalyticsSite;

final readonly class SiteRow
{
    /**
     * Create a new SiteRow instance.
     *
     * An Analytics site as lists and site pickers show it.
     *
     * @param  int  $id
     * @param  string  $name
     * @param  list<string>  $domains  The hostnames it collects from.
     * @param  bool  $verified  Whether one of its hostnames is a verified domain of the project.
     * @param  bool  $collecting  Whether it accepts data now (verified, and collection isn't paused).
     * @param  string|null  $lastEventAt  ISO 8601.
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $domains,
        public bool $verified,
        public bool $collecting,
        public ?string $lastEventAt,
    ) {}

    /**
     * Describe a site.
     *
     * @param  AnalyticsSite  $site
     * @return self
     */
    public static function from(AnalyticsSite $site): self
    {
        return new self(
            id: $site->id,
            name: $site->name,
            domains: $site->domains,
            verified: $site->isVerified(),
            collecting: $site->isCollectionAvailable(),
            lastEventAt: $site->last_event_at?->toIso8601String(),
        );
    }
}
