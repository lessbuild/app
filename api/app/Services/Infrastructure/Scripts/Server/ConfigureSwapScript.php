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
     * Render the stage that adds a swap file and reports progress.
     *
     * @param  int  $step
     * @param  Server  $server
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
            # Size swap so RAM + swap reaches about 4 GB, so builds (Node, especially) have room on a small server;
            # keep at least 1 GB, cap at 4 GB, and never use the last 2 GB of free disk so the disk isn't filled.
            RAM_MB=\$(awk '/^MemTotal:/{printf "%d", \$2/1024}' /proc/meminfo)
            SWAP_MB=\$((4096 - RAM_MB))
            [ "\$SWAP_MB" -lt 1024 ] && SWAP_MB=1024
            DISK_FREE_MB=\$(df -Pm / | awk 'NR==2{print \$4}')
            DISK_CAP=\$((DISK_FREE_MB - 2048))
            [ "\$SWAP_MB" -gt "\$DISK_CAP" ] && SWAP_MB=\$DISK_CAP
            [ "\$SWAP_MB" -gt 4096 ] && SWAP_MB=4096
            [ "\$SWAP_MB" -lt 512 ] && SWAP_MB=512
            fallocate -l "\${SWAP_MB}M" /swapfile || dd if=/dev/zero of=/swapfile bs=1M count="\$SWAP_MB"
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
