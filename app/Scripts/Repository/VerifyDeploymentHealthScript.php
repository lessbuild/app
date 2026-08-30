<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;

class VerifyDeploymentHealthScript extends BuildProvisioningScript
{
    public static string $title = 'Verify deployment health';

    public static string $description = 'Verify the website responds and restore the previous release on failure';

    public static string $identifier = 'verified-deployment-health';

    public function script(int $step, Build $build): string
    {
        $website = $build->repository->website;
        $progress = $this->progress($step, $build);
        if (! $website->health_check_enabled) {
            return <<<SCRIPT

                # Health check disabled
                {$progress}

            SCRIPT;
        }

        $url = escapeshellarg("http://{$website->url}{$website->health_check_path}");
        $root = escapeshellarg("/var/www/{$website->deployment_slug}");

        return <<<SCRIPT

            DEPLOY_ROOT={$root}
            HEALTH_CHECK_URL={$url}

            if ! curl --fail --silent --show-error --location \
                --connect-timeout 5 --max-time 15 \
                --retry 5 --retry-delay 2 --retry-all-errors \
                --user-agent "lessbuild-health-check" \
                --output /dev/null "\$HEALTH_CHECK_URL"; then
                echo "Deployment health check failed: \$HEALTH_CHECK_URL"
                DEPLOYMENT_FAILURE_MESSAGE="Deployment health check failed"
                false
            fi

            # Ping
            {$progress}

        SCRIPT;
    }
}
