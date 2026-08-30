<?php

namespace App\Scripts\Repository;

use App\Models\Repository;
use Illuminate\Support\Facades\URL;

class PurgeOldReleasesScript
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
    public function script(int $step, Repository $repository): string
    {
        $releasesPath = escapeshellarg("/var/www/{$repository->website->deployment_slug}/releases");
        $callback = escapeshellarg(URL::signedRoute('callbacks.repository', $repository));

        return <<<SCRIPT

            RELEASES_PATH={$releasesPath}
            find "\$RELEASES_PATH" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
                | sort -nr \
                | tail -n +6 \
                | cut -d' ' -f2- \
                | while IFS= read -r release; do
                    [ -n "\$release" ] && rm -rf -- "\$release"
                done

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&repository_id={$repository->id}" {$callback}

        SCRIPT;
    }
}
