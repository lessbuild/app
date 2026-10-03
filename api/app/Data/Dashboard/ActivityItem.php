<?php

declare(strict_types=1);

namespace App\Data\Dashboard;

use Carbon\CarbonImmutable;

final readonly class ActivityItem
{
    /**
     * Create a new ActivityItem instance.
     *
     * One line of the dashboard's activity feed.
     *
     * @param  string  $kind  deploy, incident or change, for the feed's filter.
     * @param  string  $icon  The Signal icon that marks the kind.
     * @param  string  $tone  The badge tone of its outcome: success, danger, warning, info or neutral.
     * @param  string  $outcome  The outcome in a word or two ("Live", "Failed", "Resolved").
     * @param  string  $title  What happened, as a sentence.
     * @param  ?string  $actor  Who did it, when a person did.
     * @param  ?string  $project  The project it happened in, when it's in one.
     * @param  ?string  $url  Where to read more.
     * @param  CarbonImmutable  $at  When it happened.
     */
    public function __construct(
        public string $kind,
        public string $icon,
        public string $tone,
        public string $outcome,
        public string $title,
        public ?string $actor,
        public ?string $project,
        public ?string $url,
        public CarbonImmutable $at,
    ) {}
}
