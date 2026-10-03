<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cli;

use Illuminate\Http\Response;

/** `/cli/install.sh`: downloads the CLI to a directory on the PATH. */
final class ShowCliInstallerController
{
    /**
     * Send the install script, pointed at this installation's copy of the CLI.
     *
     * @return Response
     */
    public function __invoke(): Response
    {
        return response()->view('cli.install', ['source' => route('cli.download')], 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
