<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;

class SymlinkScript extends BuildProvisioningScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Symlink files';

    /**
     * Description of the script
     */
    public static string $description = 'Symlink the env file and storage folder';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'symlinked';

    /**
     * The script to run
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $root = escapeshellarg("/var/www/{$repository->website->deployment_slug}");
        $progress = $this->progress($step, $build);

        return <<<SCRIPT

            DEPLOY_ROOT={$root}
            CURRENT_PATH="\$DEPLOY_ROOT/current"
            SHARED_STORAGE="\$DEPLOY_ROOT/shared/storage"

            # Seed persistent storage from the first release, then share it.
            if [ ! -d "\$SHARED_STORAGE" ]; then
                install -d -m 775 -- "\$SHARED_STORAGE"
                if [ -d "\$CURRENT_PATH/storage" ] && [ ! -L "\$CURRENT_PATH/storage" ]; then
                    cp -a "\$CURRENT_PATH/storage/." "\$SHARED_STORAGE/"
                fi
            fi

            rm -rf -- "\$CURRENT_PATH/storage"
            ln -sfn -- "\$SHARED_STORAGE" "\$CURRENT_PATH/storage"
            ln -sfn -- "\$DEPLOY_ROOT/.env" "\$CURRENT_PATH/.env"

            install -d -m 775 -- "\$CURRENT_PATH/bootstrap/cache"
            chown -R www-data:www-data "\$SHARED_STORAGE" "\$CURRENT_PATH/bootstrap/cache"
            find "\$SHARED_STORAGE" "\$CURRENT_PATH/bootstrap/cache" -type d -exec chmod 775 {} +
            find "\$SHARED_STORAGE" "\$CURRENT_PATH/bootstrap/cache" -type f -exec chmod 664 {} +

            # Ping
            {$progress}
        SCRIPT;
    }
}
