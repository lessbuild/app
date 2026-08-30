<?php

namespace App\Scripts\Repository;

use App\Models\Build;
use App\Services\ProvisioningCallbackUrl;

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
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $setupPath = escapeshellarg("/var/www/{$repository->website->deployment_slug}/setup");
        $branch = escapeshellarg($repository->branch);
        $callback = escapeshellarg(ProvisioningCallbackUrl::buildStatus($build));

        return <<<SCRIPT

            git -C {$setupPath} checkout --force {$branch}

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&build_id={$build->id}" {$callback}

        SCRIPT;
    }
}
