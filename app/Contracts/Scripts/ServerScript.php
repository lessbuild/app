<?php

namespace App\Contracts\Scripts;

use App\Models\Server;

interface ServerScript
{
    /**
     * Render a provisioning shell fragment for the selected server and callback stage.
     *
     * @param  int  $step  Lifecycle stage reported by this fragment.
     * @param  Server  $server  Target whose settings and callback identity the script uses.
     * @return string Shell commands ready to include in the provisioning script.
     */
    public function script(int $step, Server $server): string;
}
