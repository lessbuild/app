<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Server;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;

class ConfigureSwapScript implements ServerScript
{
    public const TITLE = 'Configure Swap';

    public const DESCRIPTION = 'Configure the swap space';

    public const IDENTIFIER = 'configured-swap';

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
