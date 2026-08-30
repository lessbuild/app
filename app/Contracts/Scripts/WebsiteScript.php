<?php

namespace App\Contracts\Scripts;

use App\Models\Website;

interface WebsiteScript
{
    public function script(int $step, Website $website): string;
}
