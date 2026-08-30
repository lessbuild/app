<?php

namespace App\Scripts\Server;

use App\Contracts\Scripts\ServerScript;
use App\Models\Server;

class InstallComposerScript implements ServerScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Install Composer';

    /**
     * Description of the script
     */
    public static string $description = 'Install composer on the server';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'installed-composer';

    /**
     * Shell script to run
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
