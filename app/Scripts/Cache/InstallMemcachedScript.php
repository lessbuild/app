<?php

namespace App\Scripts\Cache;

use App\Contracts\Scripts\ServerScript;
use App\Models\Server;

class InstallMemcachedScript implements ServerScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Install Memcached';

    /**
     * Description of the script
     */
    public static string $description = 'Install Memcached and configure Memcached';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'installed-memcached';

    /**
     * Shell script to run
     */
    public function script(int $step, Server $server): string
    {
        return <<<SCRIPT
        provisionPing {$server->id} {$step}

        # Install Memcached
        apt_wait
        yes | sudo apt install memcached supervisor
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
