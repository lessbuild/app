<?php

declare(strict_types=1);

namespace App\Data\Projects;

/** A project's setup guide: every step from connecting a provider to measuring visits, and the one to do next. */
final readonly class ProjectSetup
{
    /**
     * Create a new ProjectSetup instance.
     *
     * @param  list<SetupStep>  $steps  In the order to do them.
     */
    public function __construct(public array $steps) {}

    /**
     * Count the finished steps.
     *
     * @return int
     */
    public function doneCount(): int
    {
        return count(array_filter($this->steps, fn (SetupStep $step): bool => $step->done()));
    }

    /**
     * Determine whether every step is finished.
     *
     * @return bool
     */
    public function complete(): bool
    {
        return $this->doneCount() === count($this->steps);
    }

    /**
     * Get the first step that isn't finished, which is the one to work on now.
     *
     * @return SetupStep|null
     */
    public function next(): ?SetupStep
    {
        foreach ($this->steps as $step) {
            if (! $step->done()) {
                return $step;
            }
        }

        return null;
    }
}
