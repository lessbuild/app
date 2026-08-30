<?php

namespace App\Scripts\Repository;

use App\Models\Build;
use App\Services\ProvisioningCallbackUrl;

class InstallDependenciesScript
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
        $callback = escapeshellarg(ProvisioningCallbackUrl::buildStatus($build));

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
            curl --insecure --user-agent "deployer" --data "status={$step}&build_id={$build->id}" {$callback}

        SCRIPT;
    }
}
