<?php

namespace App\Scripts\Repository;

use App\Models\Repository;

class ActivateReleaseScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Activate Release';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Activate the release on the server, and update the symlink';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'activated-release';

    /**
     * The script to run
     *
     * @param int $step
     * @param \App\Models\Repository $repository
     * @return string
     */
    public function script(int $step, Repository $repository): string
    {
        $name = $repository->website->name;
        $time = now();
        $release = $time->timestamp;
        $repository->builds()->create(['built_at' => $time]);
        $callback = config('app.url') . '/servers/release-repository/callback/status';
        $root = "/var/www/$name";

        return <<<SCRIPT

            mkdir -p $root/releases/$release
            mv $root/current $root/releases/$release
            mv /var/www/$name/setup /var/www/$name/current

            sudo chmod -R 777 /var/www/$name/current/bootstrap/cache

            # Ping
            curl --insecure --user-agent "deployer" --data "status=$step&repository_id=$repository->id" {$callback}

        SCRIPT;
    }
}
