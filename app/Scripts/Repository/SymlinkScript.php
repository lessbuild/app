<?php

namespace App\Scripts\Repository;

use App\Models\Repository;

class SymlinkScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Symlink files';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Symlink the env file and storage folder';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'symlinked';

    /**
     * The script to run
     *
     * @param  int  $step
     * @param  \App\Models\Repository  $repository
     * @return string
     */
    public function script(int $step, Repository $repository): string
    {
        $name = $repository->website->deployment_slug;
        $callback = \Illuminate\Support\Facades\URL::signedRoute('callbacks.repository', $repository);

        return <<<SCRIPT

            # Symlink the env file
            ln -sf /var/www/$name/.env /var/www/$name/current/.env

            # Move storage folder and symlink it
            STORAGE_DIR=/var/www/$name/storage
            if [ ! -d "\$STORAGE_DIR" ]; then
                mkdir \$STORAGE_DIR
            fi

            rm -rf /var/www/$name/current/storage/app/public
            ln -s -n -f -T \$STORAGE_DIR /var/www/$name/current/storage/app/public

            # Perms
            sudo chmod -R 777 /var/www/$name/current/storage

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&repository_id={$repository->id}" {$callback}
        SCRIPT;
    }
}
