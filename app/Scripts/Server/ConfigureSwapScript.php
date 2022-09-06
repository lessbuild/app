<?php

namespace App\Scripts\Server;

use App\Models\Server;

class ConfigureSwapScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Configure Swap';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Configure the swap space';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'configured-swap';

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
