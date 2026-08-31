<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;

class PurgeOldReleasesScript extends BuildProvisioningScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Purge Old Releases';

    /**
     * Description of the script
     */
    public static string $description = 'Purge the old releases on the server';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'purged-releases';

    /**
     * The script to run
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $releasesPath = escapeshellarg("/var/www/{$repository->website->deployment_slug}/releases");
        $releaseRetention = max(2, min(20, (int) $repository->website->release_retention));
        $firstPrunedRelease = $releaseRetention + 1;
        $progress = $this->progress($step, $build);

        return <<<SCRIPT

            RELEASES_PATH={$releasesPath}
            find "\$RELEASES_PATH" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
                | sort -nr \
                | tail -n +{$firstPrunedRelease} \
                | cut -d' ' -f2- \
                | while IFS= read -r release; do
                    [ -n "\$release" ] && rm -rf -- "\$release"
                done

            # Ping
            {$progress}

        SCRIPT;
    }
}
