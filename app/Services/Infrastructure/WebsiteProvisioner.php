<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\WebsiteScript;
use App\Models\Website;
use App\Services\Infrastructure\Scripts\Website\AddWebsiteToCaddyScript;
use App\Services\Infrastructure\Scripts\Website\CreateMysqlDatabaseScript;
use App\Services\Infrastructure\Scripts\Website\WriteEnvFileScript;
use RuntimeException;

/**
 * Sets a website up on its server over SSH: the Caddy site, the MySQL database and user, and the .env file. Each stage
 * reports back to the website's signed callbacks; a failure uploads the log and reports the exit code.
 */
class WebsiteProvisioner
{
    /** @var list<class-string<WebsiteScript>> */
    public const STAGES = [AddWebsiteToCaddyScript::class, CreateMysqlDatabaseScript::class, WriteEnvFileScript::class];

    public function __construct(private readonly RemoteScriptRunner $runner) {}

    public function start(Website $website): void
    {
        $server = $website->server ?? throw new RuntimeException('The website has no server.');
        $this->runner->start($server, $this->script($website), $website->deployment_slug);
    }

    public function script(Website $website): string
    {
        $failure = escapeshellarg(ProvisioningCallbackUrl::websiteFailure($website));
        $logCallback = escapeshellarg(ProvisioningCallbackUrl::websiteLog($website));
        $log = escapeshellarg("/tmp/lessbuild-website-provisioning-{$website->id}.log");
        $upload = escapeshellarg("/tmp/lessbuild-website-provisioning-{$website->id}.upload.log");
        $limit = max(1, (int) config('infrastructure.server_log_max_characters'));
        $script = <<<SCRIPT
        #!/bin/bash
        set -Eeuo pipefail
        LOG_FILE={$log}
        LOG_UPLOAD_FILE={$upload}
        uploadWebsiteProvisioningLog() {
            tail -c {$limit} -- "\$LOG_FILE" > "\$LOG_UPLOAD_FILE"
            curl --silent --show-error --retry 2 --data-urlencode "log@\$LOG_UPLOAD_FILE" {$logCallback} || true
        }
        websiteProvisioningFailed() {
            exit_code=\$?
            trap - ERR
            uploadWebsiteProvisioningLog
            curl --silent --show-error --data "exit_code=\$exit_code&message=Remote website provisioning failed" {$failure} || true
            rm -f -- "\$LOG_FILE" "\$LOG_UPLOAD_FILE"
            exit "\$exit_code"
        }
        trap websiteProvisioningFailed ERR
        : > "\$LOG_FILE"
        exec > >(tee -a "\$LOG_FILE") 2>&1

        SCRIPT;
        foreach (self::STAGES as $index => $class) {
            $script .= app($class)->script($index + 1, $website)."\n";
        }

        return $script."uploadWebsiteProvisioningLog\nrm -f -- \"\$LOG_FILE\" \"\$LOG_UPLOAD_FILE\"\n";
    }

    public static function finalStage(): int
    {
        return count(self::STAGES);
    }
}
