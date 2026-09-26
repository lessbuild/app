<?php

namespace App\Modules\Deployer\Abstracts\Scripts;

use App\Modules\Deployer\Contracts\Scripts\WebsiteScript;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\ProvisioningCallbackUrl;

abstract class WebsiteProvisioningScript implements WebsiteScript
{
    /**
     * Render log-upload and signed website progress commands.
     *
     * @param  int  $step  The provisioning stage to report.
     * @param  Website  $website  The website supplying the callback identity.
     * @return string Shell commands to upload the log and submit stage progress.
     */
    protected function progress(int $step, Website $website): string
    {
        $callback = escapeshellarg(ProvisioningCallbackUrl::websiteStatus($website));
        $payload = escapeshellarg(http_build_query([
            'status' => $step,
            'website_id' => $website->id,
        ]));

        return "uploadWebsiteProvisioningLog\ncurl --fail --silent --show-error --retry 2 --user-agent \"deployer\" --data {$payload} {$callback}";
    }
}
