<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Server;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;
use App\Services\Security\HardeningScripts;

class UpdateDependenciesScript implements ServerScript
{
    public const TITLE = 'Initialise Server';

    public const DESCRIPTION = 'Initialise the server, add ssh keys, update IP address.';

    public const IDENTIFIER = 'initialised-server';

    /**
     * Render the stage that updates the system's packages and adds the PHP package repository.
     *
     * @param  int  $step
     * @param  Server  $server
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        $phpUpdates = HardeningScripts::phpUpdateSource();

        return <<<SCRIPT

        provisionPing {$server->id} {$step}

        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get update
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold upgrade -y
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y software-properties-common ca-certificates curl gnupg ufw
        sudo apt-add-repository ppa:ondrej/php -y
        # Automatic security updates only take Ubuntu's own packages unless told to include PHP's source too.
        {$phpUpdates}
        SCRIPT;
    }
}
