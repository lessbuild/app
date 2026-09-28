<?php

declare(strict_types=1);

namespace App\Data\Projects;

final readonly class ChecklistStep
{
    /**
     * Create a new ChecklistStep instance.
     *
     * One step of a new project's getting-started checklist.
     *
     * @param  string  $label  What the step is.
     * @param  string  $description  What doing it gives the person.
     * @param  bool  $done  Whether the project has already done it.
     * @param  ?string  $actionLabel  The button text that starts the step.
     * @param  ?string  $actionUrl  Where that button goes.
     */
    public function __construct(
        public string $label,
        public string $description,
        public bool $done,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
    ) {}
}
