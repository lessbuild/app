<?php

namespace App\Contracts\Scripts;

use App\Models\Build;

interface BuildScript
{
    public function script(int $step, Build $build): string;
}
