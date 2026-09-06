<?php

namespace App\Scripts\Server;

use App\Contracts\Scripts\ServerScript;
use App\Models\Server;

class UpdateDependenciesScript implements ServerScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Initialise Server';

    /**
     * Description of the script
     */
    public static string $description = 'Initialise the server, add ssh keys, update IP address.';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'initialised-server';

    /**
     * Shell script to run
     */
    public function script(int $step, Server $server): string
    {
        return <<<SCRIPT

        provisionPing {$server->id} {$step}

        apt_wait
        sudo apt-get update
        apt_wait
        sudo apt-get upgrade -y
        apt_wait
        sudo apt-get install -y software-properties-common ca-certificates curl gnupg ufw
        sudo apt-add-repository ppa:ondrej/php -y
        SCRIPT;
    }
}
