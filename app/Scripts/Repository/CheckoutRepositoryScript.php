<?php

namespace App\Scripts\Repository;

use App\Models\Repository;

class CheckoutRepositoryScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Checkout Repository';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Checkout the repository on the server';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'checked-repository';

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
        $callback = config('app.url') . '/servers/release-repository/callback/status';

        return <<<SCRIPT

            git -c /var/www/$name/setup checkout main

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&repository_id={$repository->id}" {$callback}

        SCRIPT;
    }
}
