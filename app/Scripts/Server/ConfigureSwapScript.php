<?php

namespace App\Scripts\Server;

use App\Contracts\Scripts\ServerScript;
use App\Models\Server;

class ConfigureSwapScript implements ServerScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Configure Swap';

    /**
     * Description of the script
     */
    public static string $description = 'Configure the swap space';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'configured-swap';

    /**
     * Shell script to run
     */
    public function script(int $step, Server $server): string
    {
        return <<<SCRIPT

        apt_wait

        provisionPing {$server->id} {$step}

        if [ -f /swapfile ]; then
            echo "Swap exists."
        else
            fallocate -l 1G /swapfile
            chmod 600 /swapfile
            mkswap /swapfile
            swapon /swapfile
            echo "/swapfile none swap sw 0 0" >> /etc/fstab
            echo "vm.swappiness=30" >> /etc/sysctl.conf
            echo "vm.vfs_cache_pressure=50" >> /etc/sysctl.conf
        fi
        SCRIPT;
    }
}
