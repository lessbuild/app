<?php

namespace App\Data;

class PreviewInitialization
{
    /**
     * Describe the one-time, application-owned initialization command for a preview.
     *
     * @param  string  $command  A curated command from the selected application template.
     */
    public function __construct(
        public readonly string $command,
    ) {}
}
