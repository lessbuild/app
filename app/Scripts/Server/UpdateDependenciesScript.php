<?php

namespace App\Scripts\Server;

use App\Models\Server;

class UpdateDependenciesScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Initialise Server';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Initialise the server, add ssh keys, update IP address.';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'initialised-server';

    /**
     * Shell script to run
     *
     * @param int $step
     * @param \App\Models\Server $server
     * @return string
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
        sudo apt-get install -y --force-yes software-properties-common
        sudo apt-add-repository ppa:ondrej/php -y
        SCRIPT;
    }
}
