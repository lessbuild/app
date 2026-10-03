<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Web;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;

class InstallCaddyScript implements ServerScript
{
    public const TITLE = 'Install Caddy';

    public const DESCRIPTION = 'Install Caddy server and configure Caddy.';

    public const IDENTIFIER = 'installed-caddy';

    /**
     * Render the stage that installs Caddy, sets it to load each website's site file, and reports progress.
     *
     * @param  int  $step
     * @param  Server  $server
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        return <<<SCRIPT
        provisionPing {$server->id} {$step}

        # Install Dependencies
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y debian-keyring debian-archive-keyring apt-transport-https

        # Add Caddy GPG
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg

        # Add Caddy Repository
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list

        # Install Caddy
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get update
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold upgrade -y
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y caddy

        # Enable Caddy Service
        sudo systemctl enable --now caddy

        # Make website directory
        mkdir -p /etc/caddy/websites

        # Preserve existing operator configuration and add the managed import once.
        backupManagedFile /etc/caddy/Caddyfile
        if [ -s /etc/caddy/Caddyfile ] && [ ! -e /etc/caddy/Caddyfile.pre-buildpusher ]; then
            cp -a /etc/caddy/Caddyfile /etc/caddy/Caddyfile.pre-buildpusher
        fi
        grep -qxF 'import /etc/caddy/websites/*' /etc/caddy/Caddyfile 2>/dev/null || printf '\nimport /etc/caddy/websites/*\n' >> /etc/caddy/Caddyfile
        caddy validate --config /etc/caddy/Caddyfile
        SCRIPT;
    }
}
