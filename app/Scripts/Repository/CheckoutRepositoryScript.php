<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;

class CheckoutRepositoryScript extends BuildProvisioningScript
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
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $setupPath = escapeshellarg("/var/www/{$repository->website->deployment_slug}/setup");
        $branch = escapeshellarg($repository->branch);
        $progress = $this->progress($step, $build);

        return <<<SCRIPT

            git -C {$setupPath} checkout --force {$branch}

            # Ping
            {$progress}

        SCRIPT;
    }
}
