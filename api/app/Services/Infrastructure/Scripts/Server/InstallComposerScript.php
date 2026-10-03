<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Server;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;

class InstallComposerScript implements ServerScript
{
    public const TITLE = 'Install Composer';

    public const DESCRIPTION = 'Install composer on the server';

    public const IDENTIFIER = 'installed-composer';

    /**
     * Render the stage that installs Composer and reports progress.
     *
     * @param  int  $step
     * @param  Server  $server
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        return <<<SCRIPT

        provisionPing {$server->id} {$step}

        apt_wait
        DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y composer
        SCRIPT;
    }
}
