<?php

declare(strict_types=1);

namespace App\Contracts\Infrastructure;

use App\Models\Website;

interface WebsiteScript
{
    /**
     * Render the shell commands for one website provisioning stage, ending with a progress report for `$step`.
     *
     * @param  int  $step
     * @param  Website  $website
     * @return string
     */
    public function script(int $step, Website $website): string;
}
