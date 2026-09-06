<?php

namespace App\Scripts\Server;

use App\Contracts\Scripts\ServerScript;
use App\Models\Server;

class EndScript implements ServerScript
{
    public static string $title = 'Finish provisioning';

    public static string $description = 'Finish setup and enable automatic system updates';

    public static string $identifier = 'finished-provisioning';

    /**
     * Base Script
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
        install -d -m 755 /etc/buildpusher
        printf '%s\n' {$server->id} > /etc/buildpusher/managed
        printf 'server_id=%s\ncompleted_at=%s\nbackup_dir=%s\n' {$server->id} "$(date -u +%FT%TZ)" "\$BACKUP_DIR" > /etc/buildpusher/provisioning-manifest
        chmod 644 /etc/buildpusher/managed
        chmod 600 /etc/buildpusher/provisioning-manifest
        rm -f -- "\$LOG_FILE" "\$LOG_UPLOAD_FILE"
        SCRIPT;
    }
}
