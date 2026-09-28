<?php

declare(strict_types=1);

namespace App\Contracts\Infrastructure;

use App\Models\Server;

/**
 * One stage of a server's provisioning script. Each script also names its stage with constants: TITLE, DESCRIPTION,
 * and IDENTIFIER (the stage's stable name, as Deployer called it).
 */
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
