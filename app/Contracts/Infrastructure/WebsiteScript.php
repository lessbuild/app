<?php

declare(strict_types=1);

namespace App\Contracts\Infrastructure;

use App\Models\Website;

interface WebsiteScript
{
    /** Shell commands for one website provisioning stage, ending with a progress report for `$step`. */
    public function script(int $step, Website $website): string;
}
