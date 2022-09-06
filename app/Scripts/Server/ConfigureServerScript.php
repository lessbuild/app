<?php

namespace App\Scripts\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class ConfigureServerScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Configure Server';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Configure the server, users and authorization';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'configured-server';

    /**
     * Shell script to run
     *
     * @param int $step
     * @param \App\Models\Server $server
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        $PUBLIC_KEY = env('SSH_PUBLIC_KEY');
        $PASSWORD = Str::random();
        $SERVER_NAME = $server->name;
        $NAME = Auth::user()->name;
        $EMAIL = Auth::user()->email;

        Session::put('root_password', $PASSWORD);

        return <<<SCRIPT
        apt_wait

        provisionPing {$server->id} {$step}

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

        # Setup User
        useradd $SERVER_NAME
        mkdir -p /home/$SERVER_NAME/.ssh
        mkdir -p /home/$SERVER_NAME/.$SERVER_NAME
        adduser $SERVER_NAME sudo

        # Setup Bash For User
        chsh -s /bin/bash $SERVER_NAME
        cp /root/.profile /home/$SERVER_NAME/.profile
        cp /root/.bashrc /home/$SERVER_NAME/.bashrc

        # Set The Sudo Password For The User
        PASSWORD=$(mkpasswd -m sha-512 $PASSWORD)
        usermod --password $PASSWORD $SERVER_NAME

        # Build Formatted Keys & Copy Keys To
        cat > /root/.ssh/authorized_keys << EOF
            # Laravel
            $PUBLIC_KEY
        EOF
        cp /root/.ssh/authorized_keys /home/$SERVER_NAME/.ssh/authorized_keys

        # Create The Server SSH Key
        ssh-keygen -f /home/$SERVER_NAME/.ssh/id_rsa -t rsa -N ''

        # Copy Source Control Public Keys Into Known Hosts File
        ssh-keyscan -H github.com >> /home/$SERVER_NAME/.ssh/known_hosts
        ssh-keyscan -H bitbucket.org >> /home/$SERVER_NAME/.ssh/known_hosts
        ssh-keyscan -H gitlab.com >> /home/$SERVER_NAME/.ssh/known_hosts

        # Configure Git Settings
        git config --global user.name "$NAME"
        git config --global user.email "$EMAIL"
        SCRIPT;
    }
}
