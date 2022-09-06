<?php

namespace App\Scripts\Cache;

use App\Models\Server;

class InstallMemcachedScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Install Memcached';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Install Memcached and configure Memcached';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'installed-memcached';

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
