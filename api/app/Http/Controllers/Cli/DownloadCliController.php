<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cli;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** `/cli/buildpusher`: the command-line tool, one PHP file. */
final class DownloadCliController
{
    /**
     * Send the CLI script as plain text, so it can be read before it's run.
     *
     * @return BinaryFileResponse
     */
    public function __invoke(): BinaryFileResponse
    {
        return response()->file(resource_path('cli/buildpusher.php'), [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
