<?php

namespace App\Modules\Deployer\Contracts;

use App\Modules\Deployer\Data\ServerTroubleshootingTerminalSize;
use App\Modules\Deployer\Models\Server;

interface ServerTroubleshootingTransport
{
    /** Open a started, server-owned connection for the supplied active server. */
    public function connect(Server $server, ServerTroubleshootingTerminalSize $size): ServerTroubleshootingConnection;
}
