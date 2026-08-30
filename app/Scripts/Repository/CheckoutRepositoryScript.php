<?php

namespace App\Scripts\Repository;

use App\Models\Repository;
use Illuminate\Support\Facades\URL;

class CheckoutRepositoryScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Checkout Repository';

    /**
     * Description of the script
     */
    public static string $description = 'Checkout the repository on the server';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'checked-repository';

    /**
     * The script to run
     */
    public function script(int $step, Repository $repository): string
    {
        $setupPath = escapeshellarg("/var/www/{$repository->website->deployment_slug}/setup");
        $branch = escapeshellarg($repository->branch);
        $callback = escapeshellarg(URL::signedRoute('callbacks.repository', $repository));

        return <<<SCRIPT

            git -C {$setupPath} checkout --force {$branch}

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&repository_id={$repository->id}" {$callback}

        SCRIPT;
    }
}
