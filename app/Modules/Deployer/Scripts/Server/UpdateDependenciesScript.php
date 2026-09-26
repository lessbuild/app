<?php

namespace App\Modules\Deployer\Scripts\Server;

use App\Modules\Deployer\Contracts\Scripts\ServerScript;
use App\Modules\Deployer\Models\Server;

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
        sudo env DEBIAN_FRONTEND=noninteractive apt-get update
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold upgrade -y
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y software-properties-common ca-certificates curl gnupg ufw
        sudo apt-add-repository ppa:ondrej/php -y
        SCRIPT;
    }
}
