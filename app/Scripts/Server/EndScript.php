<?php

namespace App\Scripts\Server;

class EndScript
{
    /**
     * Base Script
     *
     * @return string
     */
    public function script(): string
    {
        return <<<'SCRIPT'
        # Run periodically
        cat > /etc/apt/apt.conf.d/10periodic << EOF
            APT::Periodic::Update-Package-Lists "1";
            APT::Periodic::Download-Upgradeable-Packages "1";
            APT::Periodic::AutocleanInterval "7";
            APT::Periodic::Unattended-Upgrade "1";
        EOF
        SCRIPT;
    }
}
