<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Cache;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;

class InstallMemcachedScript implements ServerScript
{
    public const TITLE = 'Install Memcached';

    public const DESCRIPTION = 'Install Memcached and configure Memcached';

    public const IDENTIFIER = 'installed-memcached';

    /**
     * Render the stage that installs Memcached and reports progress.
     *
     * @param  int  $step
     * @param  Server  $server
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        return <<<SCRIPT
        provisionPing {$server->id} {$step}

        # Install Memcached
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y memcached supervisor
        backupManagedFile /etc/memcached.conf
        sed -i -E 's/^-l .*/-l 127.0.0.1/' /etc/memcached.conf
        service memcached restart

        # Configure Supervisor Autostart
        systemctl enable supervisor.service
        service supervisor start

        # Keep kernel link protections at the operating-system default.
        SCRIPT;
    }
}
