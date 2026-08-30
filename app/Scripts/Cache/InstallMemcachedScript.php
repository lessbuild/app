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
        yes | sudo apt install memcached
        sed -i 's/-l 127.0.0.1/-l 0.0.0.0/' /etc/memcached.conf
        service memcached restart

        # Configure Supervisor Autostart
        systemctl enable supervisor.service
        service supervisor start

        # Disable protected_regular
        sudo sed -i "s/fs.protected_regular = .*/fs.protected_regular = 0/" /usr/lib/sysctl.d/protect-links.conf

        # Run service
        sysctl --system
        SCRIPT;
    }
}
