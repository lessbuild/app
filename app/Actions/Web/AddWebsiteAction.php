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
     * @throws \Exception
     */
    public function handle(): void
    {
        $failureCallback = ProvisioningCallbackUrl::websiteFailure($this->website);
        $this->script = <<<SCRIPT
        #!/bin/bash
        set -Eeuo pipefail
        trap 'exit_code=$?; curl --silent --show-error --data "exit_code=\$exit_code&message=Remote website provisioning failed" "{$failureCallback}"; exit \$exit_code' ERR

        SCRIPT;

        $this->script .= $this->renderer->website($this->website, $this->plan->scripts());

        $this->makeScriptFile($this->website->name);

        $this->upload();

        $this->run();
    }
}
