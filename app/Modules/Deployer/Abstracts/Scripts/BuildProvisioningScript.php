<?php

namespace App\Modules\Deployer\Abstracts\Scripts;

use App\Modules\Deployer\Contracts\Scripts\BuildScript;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Services\ProvisioningCallbackUrl;

abstract class BuildProvisioningScript implements BuildScript
{
    /**
     * Render a signed, shell-escaped build progress callback.
     *
     * @param  int  $step  The provisioning stage to report.
     * @param  Build  $build  The build supplying the callback identity.
     * @return string A curl command; rendering does not send the callback.
     */
    protected function progress(int $step, Build $build): string
    {
        $callback = escapeshellarg(ProvisioningCallbackUrl::buildStatus($build));
        $payload = escapeshellarg(http_build_query([
            'status' => $step,
            'build_id' => $build->id,
        ]));

        return "curl --fail --silent --show-error --retry 2 --user-agent \"deployer\" --data {$payload} {$callback}";
    }
}
