<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Website;

use App\Contracts\Infrastructure\WebsiteScript;
use App\Models\Website;
use App\Services\Infrastructure\ProvisioningCallbackUrl;

abstract class WebsiteProvisioningScript implements WebsiteScript
{
    /** Upload the log so far and report that `$step` finished. */
    protected function progress(int $step, Website $website): string
    {
        $callback = escapeshellarg(ProvisioningCallbackUrl::websiteStatus($website));
        $payload = escapeshellarg(http_build_query(['status' => $step, 'website_id' => $website->id]));

        return "uploadWebsiteProvisioningLog\ncurl --fail --silent --show-error --retry 2 --user-agent \"deployer\" --data {$payload} {$callback}";
    }
}
