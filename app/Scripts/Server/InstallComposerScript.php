<?php

namespace App\Scripts\Server;

use App\Models\Server;

class InstallComposerScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Install Composer';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Install composer on the server';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'installed-composer';

    /**
     * Shell script to run
     *
     * @param int $step
     * @param \App\Models\Server $server
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        return <<<SCRIPT

        provisionPing {$server->id} {$step}

        apt_wait
        apt install -y composer
        SCRIPT;
    }
}
