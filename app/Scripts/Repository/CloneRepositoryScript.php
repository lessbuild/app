<?php

namespace App\Scripts\Repository;

use App\Models\Repository;

class CloneRepositoryScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Clone Repository';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Clone the repository on the server';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'cloned-repository';

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
        $repo = "https://{$repository->provider->token}@{$repository->url}";

        return <<<SCRIPT

            rm -f /etc/cron.d/$repository->name
            rm -r /var/www/$name/setup
            git clone $repo /var/www/$name/setup

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&repository_id={$repository->id}" {$callback}

        SCRIPT;
    }
}
