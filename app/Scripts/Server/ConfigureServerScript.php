<?php

namespace App\Scripts\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class ConfigureServerScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Configure Server';

    /**
     * Description of the script
     */
    public static string $description = 'Configure the server, users and authorization';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'configured-server';

    /**
     * Shell script to run
     */
    public function script(int $step, Server $server): string
    {
        $publicKey = escapeshellarg($server->ssh_public_key);
        $password = Str::random(32);
        $shellPassword = escapeshellarg($password);
        $serverName = escapeshellarg($server->name);
        $name = escapeshellarg($server->user->name);
        $email = escapeshellarg($server->user->email);

        Session::put('root_password', $password);

        return <<<SCRIPT
        apt_wait

        provisionPing {$server->id} {$step}

        SERVER_NAME={$serverName}
        ROOT_PASSWORD={$shellPassword}
        PUBLIC_KEY={$publicKey}

        # Disable Password Authentication Over SSH
        sed -i "/PasswordAuthentication yes/d" /etc/ssh/sshd_config
        echo "" | sudo tee -a /etc/ssh/sshd_config
        echo "" | sudo tee -a /etc/ssh/sshd_config
        echo "PasswordAuthentication no" | sudo tee -a /etc/ssh/sshd_config

        # Restart SSH
        ssh-keygen -A
        service ssh restart

        # Create The Root SSH Directory If Necessary
        if [ ! -d /root/.ssh ]
        then
            mkdir -p /root/.ssh
            touch /root/.ssh/authorized_keys
        fi

        # Set root password and create the deploy user
        echo "root:\$ROOT_PASSWORD" | chpasswd
        if ! id -u "\$SERVER_NAME" >/dev/null 2>&1; then
            useradd --create-home --shell /bin/bash "\$SERVER_NAME"
        fi
        echo "\$SERVER_NAME:\$ROOT_PASSWORD" | chpasswd
        usermod -aG sudo "\$SERVER_NAME"

        # Preserve the cloud key and make the generated server key authoritative
        touch /root/.ssh/authorized_keys
        grep -qxF "\$PUBLIC_KEY" /root/.ssh/authorized_keys || printf '%s\\n' "\$PUBLIC_KEY" >> /root/.ssh/authorized_keys
        install -d -m 700 -o "\$SERVER_NAME" -g "\$SERVER_NAME" "/home/\$SERVER_NAME/.ssh"
        cp /root/.ssh/authorized_keys "/home/\$SERVER_NAME/.ssh/authorized_keys"
        chown "\$SERVER_NAME:\$SERVER_NAME" "/home/\$SERVER_NAME/.ssh/authorized_keys"
        chmod 600 "/home/\$SERVER_NAME/.ssh/authorized_keys"

        # Create The Server SSH Key
        sudo -u "\$SERVER_NAME" ssh-keygen -f "/home/\$SERVER_NAME/.ssh/id_rsa" -t rsa -N ''

        # Copy Source Control Public Keys Into Known Hosts File
        ssh-keyscan -H github.com >> "/home/\$SERVER_NAME/.ssh/known_hosts"
        ssh-keyscan -H bitbucket.org >> "/home/\$SERVER_NAME/.ssh/known_hosts"
        ssh-keyscan -H gitlab.com >> "/home/\$SERVER_NAME/.ssh/known_hosts"
        chown "\$SERVER_NAME:\$SERVER_NAME" "/home/\$SERVER_NAME/.ssh/known_hosts"

        # Configure Git Settings
        sudo -u "\$SERVER_NAME" git config --global user.name {$name}
        sudo -u "\$SERVER_NAME" git config --global user.email {$email}
        SCRIPT;
    }
}
