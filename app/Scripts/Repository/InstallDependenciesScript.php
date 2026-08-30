<?php

namespace App\Scripts\Repository;

use App\Models\Repository;

class InstallDependenciesScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Install Repository Dependencies';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Install the repository dependencies on the server';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'installed-repository-dependencies';

    /**
     * The script to run
     *
     * @param  int  $step
     * @param  \App\Models\Repository  $repository
     * @return string
     */
    public function script(int $step, Repository $repository): string
    {
        $name = $repository->website->name;
        $callback = \Illuminate\Support\Facades\URL::signedRoute('callbacks.repository', $repository);

        return <<<SCRIPT

            cd /var/www/{$name}/setup

            # Install dependencies
            yes | composer install

            # Composer update
            yes | composer update
            yes | composer dump

            # Install packages
            npm install
            npm run build

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&repository_id={$repository->id}" $callback

        SCRIPT;
    }
}
