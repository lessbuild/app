<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Languages;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;

class InstallNodeScript implements ServerScript
{
    public const TITLE = 'Install Node';

    public const DESCRIPTION = 'Install Node and configure Node';

    public const IDENTIFIER = 'installed-node';

    /**
     * Shell script to run
     *
     * @param  int  $step
     * @param  Server  $server
     * @return string
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
