<?php

namespace App\Contracts;

use App\Data\ServerTroubleshootingTerminalSize;
use App\Models\Server;

interface ServerTroubleshootingTransport
{
    /** Open a started, server-owned connection for the supplied active server. */
    public function connect(Server $server, ServerTroubleshootingTerminalSize $size): ServerTroubleshootingConnection;
}
