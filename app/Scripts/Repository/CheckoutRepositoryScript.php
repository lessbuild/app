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
        $remoteBranch = escapeshellarg("origin/{$repository->branch}");
        $revision = $build->revision;
        $progress = $this->progress($step, $build);

        if ($revision) {
            if (! preg_match('/\A[0-9a-f]{40,64}\z/D', $revision)) {
                throw new \InvalidArgumentException('The build revision is invalid.');
            }

            $revision = escapeshellarg($revision);

            return <<<SCRIPT

                git -C {$setupPath} rev-parse --verify {$revision}^{commit} >/dev/null
                git -C {$setupPath} merge-base --is-ancestor {$revision} {$remoteBranch}
                git -C {$setupPath} checkout --detach --force {$revision}

                # Ping
                {$progress}

            SCRIPT;
        }

        return <<<SCRIPT

            git -C {$setupPath} checkout --force {$branch}

            # Ping
            {$progress}

        SCRIPT;
    }
}
