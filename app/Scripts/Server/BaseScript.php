<?php

namespace App\Scripts\Server;

class BaseScript
{
    /**
     * Base Script
     *
     * @return string
     */
    public function script(): string
    {
        $callback = config('app.url') . '/servers/provisioning/callback/status';

        return <<<SCRIPT
        #!/bin/bash

        export DEBIAN_FRONTEND=noninteractive

        rm -f /etc/cron.d/setup-server

        provisionPing() {
          curl --insecure --user-agent "deployer" --data "status=$2&server_id=$1" {$callback}
        }

        apt_wait () {
            while fuser /var/lib/dpkg/lock >/dev/null 2>&1 ; do
                echo "Waiting: dpkg/lock is locked..."
                sleep 5
            done

            while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 ; do
                echo "Waiting: dpkg/lock-frontend is locked..."
                sleep 5
            done

            while fuser /var/lib/apt/lists/lock >/dev/null 2>&1 ; do
                echo "Waiting: lists/lock is locked..."
                sleep 5
            done

            if [ -f /var/log/unattended-upgrades/unattended-upgrades.log ]; then
                while fuser /var/log/unattended-upgrades/unattended-upgrades.log >/dev/null 2>&1 ; do
                    echo "Waiting: unattended-upgrades is locked..."
                    sleep 5
                done
            fi
        }
        SCRIPT;
    }
}
