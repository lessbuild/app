<?php

namespace App\Scripts\Web;

use App\Models\Server;

class InstallCaddyScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Install Caddy';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Install Caddy server and configure Caddy.';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'installed-caddy';

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

        # Install Dependencies
        apt_wait
        yes | sudo apt install debian-keyring debian-archive-keyring apt-transport-https

        # Add Caddy GPG
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg

        # Add Caddy Repository
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list

        # Install Caddy
        apt_wait
        sudo apt-get update
        apt_wait
        sudo apt-get upgrade -y
        apt_wait
        yes | sudo apt install caddy

        # Enable Caddy Service
        sudo systemctl enable --now caddy

        # Add Websites
        echo "import /etc/caddy/websites/*" > /etc/caddy/Caddyfile

        # Make website directory
        mkdir /etc/caddy/websites
        SCRIPT;
    }
}
