<?php

namespace App\Scripts\Server;

use App\Contracts\Scripts\ServerScript;
use App\Models\Server;
use App\Services\ProvisioningCallbackUrl;

class BaseScript implements ServerScript
{
    /**
     * Base Script
     */
    public function script(int $step, Server $server): string
    {
        $callback = escapeshellarg(ProvisioningCallbackUrl::serverStatus($server));
        $failureCallback = escapeshellarg(ProvisioningCallbackUrl::serverFailure($server));
        $logCallback = escapeshellarg(ProvisioningCallbackUrl::serverLog($server));
        $logFile = escapeshellarg("/tmp/lessbuild-server-provisioning-{$server->id}.log");
        $logUploadFile = escapeshellarg("/tmp/lessbuild-server-provisioning-{$server->id}.upload.log");
        $logLimit = max(1, (int) config('lessbuild.server_log_max_characters'));

        return <<<SCRIPT
        #!/bin/bash

        set -Eeuo pipefail

        LOG_FILE={$logFile}
        LOG_UPLOAD_FILE={$logUploadFile}

        uploadProvisioningLog() {
          tail -c {$logLimit} -- "\$LOG_FILE" > "\$LOG_UPLOAD_FILE"
          curl --silent --show-error --retry 2 \
            --data-urlencode "log@\$LOG_UPLOAD_FILE" \
            {$logCallback} || true
        }

        provisioningFailed() {
          exit_code=\$?
          trap - ERR
          uploadProvisioningLog
          curl --silent --show-error \
            --data "exit_code=\$exit_code&message=Remote server provisioning failed" \
            {$failureCallback} || true
          rm -f -- "\$LOG_FILE" "\$LOG_UPLOAD_FILE"
          exit "\$exit_code"
        }

        trap provisioningFailed ERR
        : > "\$LOG_FILE"
        exec > >(tee -a "\$LOG_FILE") 2>&1

        export DEBIAN_FRONTEND=noninteractive

        rm -f /etc/cron.d/setup-server

        provisionPing() {
          uploadProvisioningLog
          curl --fail --silent --show-error --retry 2 --user-agent "deployer" --data "status=$2&server_id=$1" {$callback}
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
