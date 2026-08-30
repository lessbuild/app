<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;
use App\Services\ProvisioningCallbackUrl;

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
        $revisionCallback = escapeshellarg(ProvisioningCallbackUrl::buildRevision($build));
        $progress = $this->progress($step, $build);

        if ($revision) {
            if (! preg_match('/\A[0-9a-f]{40,64}\z/D', $revision)) {
                throw new \InvalidArgumentException('The build revision is invalid.');
            }

            $revision = escapeshellarg($revision);
            $checkout = <<<SCRIPT
            git -C {$setupPath} rev-parse --verify {$revision}^{commit} >/dev/null
            git -C {$setupPath} merge-base --is-ancestor {$revision} {$remoteBranch}
            git -C {$setupPath} checkout --detach --force {$revision}
            SCRIPT;
        } else {
            $checkout = "git -C {$setupPath} checkout --force {$branch}";
        }

        return <<<SCRIPT

            {$checkout}

            DEPLOYED_REVISION="$(git -C {$setupPath} rev-parse HEAD)"
            DEPLOYED_MESSAGE="$(git -C {$setupPath} log -1 --format=%B)"
            DEPLOYED_MESSAGE="\${DEPLOYED_MESSAGE:0:500}"
            curl --fail --silent --show-error --retry 2 --user-agent "deployer" \
                --data-urlencode "revision=\$DEPLOYED_REVISION" \
                --data-urlencode "commit_message=\$DEPLOYED_MESSAGE" \
                {$revisionCallback}

            # Ping
            {$progress}

        SCRIPT;
    }
}
