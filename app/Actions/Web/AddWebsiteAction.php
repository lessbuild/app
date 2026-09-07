<?php

namespace App\Actions\Web;

use App\Abstracts\Publishable;
use App\Models\Website;
use App\Services\ProvisioningCallbackUrl;
use App\Services\ProvisioningScriptRenderer;
use App\Services\Runner;
use App\Services\WebsiteProvisioningPlan;

class AddWebsiteAction extends Publishable
{
    private Website $website;

    private ProvisioningScriptRenderer $renderer;

    private WebsiteProvisioningPlan $plan;

    /**
     * Bind website provisioning to its server, ordered setup plan, and remote script renderer.
     *
     * @param  Website  $website  Website supplying its provisioning state and managed placement.
     * @param  Runner|null  $runner  Optional SSH runner; null creates the default runner for this operation.
     * @param  ProvisioningScriptRenderer|null  $renderer  Optional provisioning script renderer; null uses the application default.
     * @param  WebsiteProvisioningPlan|null  $plan  Optional ordered step plan; null resolves the corresponding application plan.
     *
     * @throws \Exception
     */
    public function __construct(
        Website $website,
        ?Runner $runner = null,
        ?ProvisioningScriptRenderer $renderer = null,
        ?WebsiteProvisioningPlan $plan = null,
    ) {
        parent::__construct($website->server, $runner);

        $this->website = $website;
        $this->renderer = $renderer ?? app(ProvisioningScriptRenderer::class);
        $this->plan = $plan ?? app(WebsiteProvisioningPlan::class);
    }

    /**
     * Render and upload website provisioning steps with stage and log callbacks, then execute them on the managed server.
     *
     * @throws \Exception
     */
    public function handle(): void
    {
        $failureCallback = escapeshellarg(ProvisioningCallbackUrl::websiteFailure($this->website));
        $logCallback = escapeshellarg(ProvisioningCallbackUrl::websiteLog($this->website));
        $logFile = escapeshellarg("/tmp/lessbuild-website-provisioning-{$this->website->id}.log");
        $logUploadFile = escapeshellarg("/tmp/lessbuild-website-provisioning-{$this->website->id}.upload.log");
        $logLimit = max(1, (int) config('lessbuild.website_log_max_characters'));
        $this->script = <<<SCRIPT
        #!/bin/bash
        set -Eeuo pipefail

        LOG_FILE={$logFile}
        LOG_UPLOAD_FILE={$logUploadFile}

        uploadWebsiteProvisioningLog() {
            tail -c {$logLimit} -- "\$LOG_FILE" > "\$LOG_UPLOAD_FILE"
            curl --silent --show-error --retry 2 \
                --data-urlencode "log@\$LOG_UPLOAD_FILE" \
                {$logCallback} || true
        }

        websiteProvisioningFailed() {
            exit_code=\$?
            trap - ERR
            uploadWebsiteProvisioningLog
            curl --silent --show-error \
                --data "exit_code=\$exit_code&message=Remote website provisioning failed" \
                {$failureCallback} || true
            rm -f -- "\$LOG_FILE" "\$LOG_UPLOAD_FILE"
            exit "\$exit_code"
        }

        trap websiteProvisioningFailed ERR
        : > "\$LOG_FILE"
        exec > >(tee -a "\$LOG_FILE") 2>&1

        SCRIPT;

        $this->script .= $this->renderer->website($this->website, $this->plan->scripts());

        $this->script .= <<<'SCRIPT'

        uploadWebsiteProvisioningLog
        rm -f -- "$LOG_FILE" "$LOG_UPLOAD_FILE"

        SCRIPT;

        $this->makeScriptFile($this->website->name);

        $this->upload();

        $this->run();
    }
}
