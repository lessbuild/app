<?php

namespace App\Scripts\Languages;

use App\Models\Server;

class InstallNodeScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Install Node';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Install Node and configure Node';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'installed-php';

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

        # Install Node
        apt_wait
        yes | sudo apt install nodejs npm

        # Update node
        sudo npm install -g n
        sudo n latest

        SCRIPT;
    }
}
