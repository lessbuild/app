<?php

namespace App\Contracts\Scripts;

use App\Models\Server;

interface ServerScript
{
    public function script(int $step, Server $server): string;
}
