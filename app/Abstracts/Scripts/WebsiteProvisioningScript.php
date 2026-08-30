<?php

namespace App\Abstracts\Scripts;

use App\Contracts\Scripts\WebsiteScript;
use App\Models\Website;
use App\Services\ProvisioningCallbackUrl;

abstract class WebsiteProvisioningScript implements WebsiteScript
{
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
