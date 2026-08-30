<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;

class InstallDependenciesScript extends BuildProvisioningScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Install Repository Dependencies';

    /**
     * Description of the script
     */
    public static string $description = 'Install the repository dependencies on the server';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'installed-repository-dependencies';

    /**
     * The script to run
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $setupPath = escapeshellarg("/var/www/{$repository->website->deployment_slug}/setup");
        $progress = $this->progress($step, $build);

        return <<<SCRIPT

            cd -- {$setupPath}

            if [ -f composer.json ]; then
                composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader
            fi

            if [ -f package.json ]; then
                if [ -f package-lock.json ]; then
                    npm ci --no-audit --no-fund
                else
                    npm install --no-audit --no-fund
                fi
                npm run build --if-present
            fi

            # Ping
            {$progress}

        SCRIPT;
    }
}
