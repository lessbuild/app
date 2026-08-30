<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;
use Illuminate\Support\Str;

class ActivateReleaseScript extends BuildProvisioningScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Activate Release';

    /**
     * Description of the script
     */
    public static string $description = 'Activate the release on the server, and update the symlink';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'activated-release';

    /**
     * The script to run
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $root = escapeshellarg("/var/www/{$repository->website->deployment_slug}");
        $release = escapeshellarg(now()->format('YmdHis').'-'.Str::lower(Str::random(6)));
        $progress = $this->progress($step, $build);

        return <<<SCRIPT

            DEPLOY_ROOT={$root}
            RELEASE_NAME={$release}
            RELEASE_PATH="\$DEPLOY_ROOT/releases/\$RELEASE_NAME"
            CURRENT_PATH="\$DEPLOY_ROOT/current"
            NEXT_LINK="\$DEPLOY_ROOT/current.next"
            PREVIOUS_RELEASE_PATH=""

            if [ -L "\$CURRENT_PATH" ]; then
                PREVIOUS_RELEASE_PATH="$(readlink -f -- "\$CURRENT_PATH" || true)"
            fi

            mkdir -p -- "\$DEPLOY_ROOT/releases"
            mv -- "\$DEPLOY_ROOT/setup" "\$RELEASE_PATH"

            # Convert the legacy directory layout on its first deployment.
            if [ -d "\$CURRENT_PATH" ] && [ ! -L "\$CURRENT_PATH" ]; then
                PREVIOUS_RELEASE_PATH="\$DEPLOY_ROOT/releases/legacy-\$RELEASE_NAME"
                mv -- "\$CURRENT_PATH" "\$PREVIOUS_RELEASE_PATH"
            fi

            ln -sfn -- "\$RELEASE_PATH" "\$NEXT_LINK"
            mv -Tf -- "\$NEXT_LINK" "\$CURRENT_PATH"

            # Ping
            {$progress}

        SCRIPT;
    }
}
