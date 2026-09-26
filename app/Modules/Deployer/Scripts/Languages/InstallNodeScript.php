<?php

namespace App\Modules\Deployer\Scripts\Languages;

use App\Modules\Deployer\Contracts\Scripts\ServerScript;
use App\Modules\Deployer\Models\Server;

class InstallNodeScript implements ServerScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Install Node';

    /**
     * Description of the script
     */
    public static string $description = 'Install Node and configure Node';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'installed-node';

    /**
     * Shell script to run
     */
    public function script(int $step, Server $server): string
    {
        return <<<SCRIPT

        provisionPing {$server->id} {$step}

        # Install Node
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y nodejs npm

        # Update node
        sudo npm install -g n
        sudo n latest

        SCRIPT;
    }
}
