<?php

declare(strict_types=1);

namespace App\Services\Deploy\Scripts;

use App\Contracts\Deploy\BuildScript;
use App\Models\Build;
use App\Services\Infrastructure\ProvisioningCallbackUrl;

abstract class BuildProvisioningScript implements BuildScript
{
    /**
     * Render a signed, shell-escaped build progress callback.
     *
     * @param  int  $step  The provisioning stage to report.
     * @param  Build  $build  The build supplying the callback identity.
     * @return string A curl command; rendering does not send the callback.
     */
    public function progress(int $step, Build $build): string
    {
        $callback = escapeshellarg(ProvisioningCallbackUrl::buildStatus($build));
        $payload = escapeshellarg(http_build_query([
            'status' => $step,
            'build_id' => $build->id,
        ]));

        return "curl --fail --silent --show-error --retry 2 --user-agent \"deployer\" --data {$payload} {$callback}";
    }
}
