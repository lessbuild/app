<?php

namespace App\Scripts\Server;

use App\Contracts\Scripts\ServerScript;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use RuntimeException;

class ConfigureServerScript implements ServerScript
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
        $password = $server->provisioningRootPassword()
            ?? throw new RuntimeException('Server provisioning credentials have not been prepared.');
        $shellPassword = escapeshellarg($password);
        $serverName = escapeshellarg($server->name);
        $name = escapeshellarg($server->user->name);
        $email = escapeshellarg($server->user->email);
        $webFirewallRules = in_array($server->type, [ServerTypeEnum::app, ServerTypeEnum::web, ServerTypeEnum::loadbalancer], true)
            ? "ufw allow 80/tcp\n        ufw allow 443/tcp"
            : '';

        return <<<SCRIPT
        apt_wait

        provisionPing {$server->id} {$step}

        SERVER_NAME={$serverName}
        ROOT_PASSWORD={$shellPassword}
        PUBLIC_KEY={$publicKey}

        # Manage SSH through a dedicated drop-in and validate before reload.
        backupManagedFile /etc/ssh/sshd_config.d/99-buildpusher.conf
        backupManagedFile /etc/ufw/user.rules
        backupManagedFile /etc/ufw/user6.rules
        install -d -m 755 /etc/ssh/sshd_config.d
        cat > /etc/ssh/sshd_config.d/99-buildpusher.conf <<'SSHD_CONFIG'
        PasswordAuthentication no
        KbdInteractiveAuthentication no
        PubkeyAuthentication yes
        PermitEmptyPasswords no
        SSHD_CONFIG
        ssh-keygen -A
        sshd -t
        systemctl reload ssh || systemctl reload sshd

        # Create The Root SSH Directory If Necessary
        if [ ! -d /root/.ssh ]
        then
            mkdir -p /root/.ssh
            touch /root/.ssh/authorized_keys
        fi

        # Create the deploy user without changing the existing root password.
        if ! id -u "\$SERVER_NAME" >/dev/null 2>&1; then
            useradd --create-home --shell /bin/bash "\$SERVER_NAME"
        fi
        backupManagedFile "/etc/sudoers.d/90-buildpusher-\$SERVER_NAME"
        backupManagedFile "/home/\$SERVER_NAME/.ssh/authorized_keys"
        echo "\$SERVER_NAME:\$ROOT_PASSWORD" | chpasswd
        usermod -aG sudo "\$SERVER_NAME"
        printf '%s ALL=(ALL) NOPASSWD:ALL\n' "\$SERVER_NAME" > "/etc/sudoers.d/90-buildpusher-\$SERVER_NAME"
        chmod 440 "/etc/sudoers.d/90-buildpusher-\$SERVER_NAME"
        visudo -cf "/etc/sudoers.d/90-buildpusher-\$SERVER_NAME"

        # Default-deny inbound traffic while keeping outbound package and API
        # access. Databases and caches remain loopback-only by default.
        ufw default deny incoming
        ufw default allow outgoing
        ufw allow "\${SSH_CONNECTION##* }/tcp" 2>/dev/null || ufw allow OpenSSH
        {$webFirewallRules}
        ufw --force enable

        # Preserve root access but do not copy unrelated root keys to the deploy user.
        touch /root/.ssh/authorized_keys
        grep -qxF "\$PUBLIC_KEY" /root/.ssh/authorized_keys || printf '%s\\n' "\$PUBLIC_KEY" >> /root/.ssh/authorized_keys
        install -d -m 700 -o "\$SERVER_NAME" -g "\$SERVER_NAME" "/home/\$SERVER_NAME/.ssh"
        printf '%s\n' "\$PUBLIC_KEY" > "/home/\$SERVER_NAME/.ssh/authorized_keys"
        chown "\$SERVER_NAME:\$SERVER_NAME" "/home/\$SERVER_NAME/.ssh/authorized_keys"
        chmod 600 "/home/\$SERVER_NAME/.ssh/authorized_keys"

        # Create The Server SSH Key
        if [ ! -f "/home/\$SERVER_NAME/.ssh/id_rsa" ]; then
            sudo -u "\$SERVER_NAME" ssh-keygen -f "/home/\$SERVER_NAME/.ssh/id_rsa" -t rsa -N ''
        fi

        # Pin source-control host keys published by each provider. Runtime
        # ssh-keyscan would trust whichever key the network returned first.
        backupManagedFile "/home/\$SERVER_NAME/.ssh/known_hosts"
        cat > "/home/\$SERVER_NAME/.ssh/known_hosts" <<'SOURCE_CONTROL_HOST_KEYS'
        github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl
        gitlab.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAfuCHKVTjquxvt6CM6tdG4SLp1Btn/nOeHHE5UOzRdf
        bitbucket.org ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIazEu89wgQZ4bqs3d63QSMzYVa0MuJ2e2gKTKqu+UUO
        SOURCE_CONTROL_HOST_KEYS
        chown "\$SERVER_NAME:\$SERVER_NAME" "/home/\$SERVER_NAME/.ssh/known_hosts"
        chmod 600 "/home/\$SERVER_NAME/.ssh/known_hosts"

        # Configure Git Settings
        sudo -u "\$SERVER_NAME" git config --global user.name {$name}
        sudo -u "\$SERVER_NAME" git config --global user.email {$email}
        SCRIPT;
    }
}
