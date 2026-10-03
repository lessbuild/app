<?php

declare(strict_types=1);

namespace App\Data\Projects;

/** One step of a project's setup guide, with where the project stands on it. */
final readonly class SetupStep
{
    /**
     * The step is finished.
     *
     * @var string
     */
    public const DONE = 'done';

    /**
     * The step has started and is finishing by itself (a server being set up, a deploy running, waiting for visits).
     *
     * @var string
     */
    public const WORKING = 'working';

    /**
     * Nothing has been done for the step yet.
     *
     * @var string
     */
    public const TODO = 'todo';

    /**
     * Create a new SetupStep instance.
     *
     * @param  string  $key  A short name for the step, e.g. "server".
     * @param  string  $title  What the step is, e.g. "Create a server".
     * @param  string  $description  Why it matters and what doing it involves.
     * @param  string  $state  DONE, WORKING or TODO.
     * @param  string|null  $detail  What was found, e.g. "web-1 is active".
     * @param  string|null  $actionLabel  The button that starts or continues the step.
     * @param  string|null  $actionUrl  Where that button goes.
     * @param  string  $icon  The Signal icon for the step.
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public string $state,
        public ?string $detail = null,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public string $icon = 'check',
    ) {}

    /**
     * Determine whether the step is finished.
     *
     * @return bool
     */
    public function done(): bool
    {
        return $this->state === self::DONE;
    }
}
