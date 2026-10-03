<?php

declare(strict_types=1);

namespace App\Data\Analytics;

final readonly class GoalDetails
{
    /**
     * Create a new GoalDetails instance.
     *
     * The settings of an analytics goal.
     *
     * @param  string  $name  The goal's name.
     * @param  string  $kind  path or event
     * @param  string  $matchType  exact or prefix
     * @param  string  $matchValue  The path or event name to match.
     * @param  bool  $active  Whether the goal is counted; paused goals keep their history.
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $matchType,
        public string $matchValue,
        public bool $active = true,
    ) {}
}
