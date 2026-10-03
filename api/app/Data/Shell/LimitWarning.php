<?php

declare(strict_types=1);

namespace App\Data\Shell;

final readonly class LimitWarning
{
    /**
     * Create a new LimitWarning instance.
     *
     * A plan allowance that's nearly or completely used, as the banner across the app says it.
     *
     * @param  string  $tone  warning, or danger once the limit is reached
     * @param  string  $message  What's used, and the plan that gives more when there is one.
     * @param  string  $linkLabel  Upgrade, or See plans.
     * @param  string  $url  The billing page, on the service's tab.
     */
    public function __construct(
        public string $tone,
        public string $message,
        public string $linkLabel,
        public string $url,
    ) {}
}
