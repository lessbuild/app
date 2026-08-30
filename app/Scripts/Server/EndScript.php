<?php

namespace App\Scripts\Server;

use App\Models\Server;

class EndScript
{
    public static string $title = 'Finish provisioning';

    public static string $description = 'Finish setup and enable automatic system updates';

    /**
     * Base Script
     *
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        return <<<SCRIPT
        # Run periodically
        cat > /etc/apt/apt.conf.d/10periodic << EOF
            APT::Periodic::Update-Package-Lists "1";
            APT::Periodic::Download-Upgradeable-Packages "1";
            APT::Periodic::AutocleanInterval "7";
            APT::Periodic::Unattended-Upgrade "1";
        EOF

        provisionPing {$server->id} {$step}
        SCRIPT;
    }
}
