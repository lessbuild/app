<?php

namespace App\Scripts\Repository;

use App\Models\Repository;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ActivateReleaseScript
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
    public function script(int $step, Repository $repository): string
    {
        $root = escapeshellarg("/var/www/{$repository->website->deployment_slug}");
        $release = escapeshellarg(now()->format('YmdHis').'-'.Str::lower(Str::random(6)));
        $callback = escapeshellarg(URL::signedRoute('callbacks.repository', $repository));

        return <<<SCRIPT

            DEPLOY_ROOT={$root}
            RELEASE_NAME={$release}
            RELEASE_PATH="\$DEPLOY_ROOT/releases/\$RELEASE_NAME"
            CURRENT_PATH="\$DEPLOY_ROOT/current"
            NEXT_LINK="\$DEPLOY_ROOT/current.next"

            mkdir -p -- "\$DEPLOY_ROOT/releases"
            mv -- "\$DEPLOY_ROOT/setup" "\$RELEASE_PATH"

            # Convert the legacy directory layout on its first deployment.
            if [ -d "\$CURRENT_PATH" ] && [ ! -L "\$CURRENT_PATH" ]; then
                mv -- "\$CURRENT_PATH" "\$DEPLOY_ROOT/releases/legacy-\$RELEASE_NAME"
            fi

            ln -sfn -- "\$RELEASE_PATH" "\$NEXT_LINK"
            mv -Tf -- "\$NEXT_LINK" "\$CURRENT_PATH"

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&repository_id={$repository->id}" {$callback}

        SCRIPT;
    }
}
