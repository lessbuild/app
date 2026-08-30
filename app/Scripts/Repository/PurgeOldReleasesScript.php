<?php

namespace App\Scripts\Repository;

use App\Models\Repository;

class PurgeOldReleasesScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Purge Old Releases';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Purge the old releases on the server';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'purged-releases';

    /**
     * The script to run
     *
     * @param  int  $step
     * @param  \App\Models\Repository  $repository
     * @return string
     */
    public function script(int $step, Repository $repository): string
    {
        $callback = \Illuminate\Support\Facades\URL::signedRoute('callbacks.repository', $repository);

        return <<<SCRIPT

            # Delete current file
            # rm -- "$0"

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&repository_id={$repository->id}" {$callback}

        SCRIPT;
    }
}
