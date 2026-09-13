<?php

namespace App\Actions\Project;

use App\Models\PreviewStackCleanup;
use App\Models\Server;
use App\Services\PreviewStackCleanupScript;
use App\Services\Runner;
use RuntimeException;

class CleanupPreviewStackAction
{
    /**
     * Render and execute cleanup against the server captured when the preview was closed.
     *
     * @param  PreviewStackCleanup  $cleanup  Immutable target and non-secret resource manifest.
     * @param  Runner  $runner  SSH runner used for the captured managed server.
     * @param  PreviewStackCleanupScript  $script  Safe remote cleanup command renderer.
     *
     * @throws RuntimeException If the captured target is invalid or the remote operation fails.
     */
    public function handle(PreviewStackCleanup $cleanup, Runner $runner, PreviewStackCleanupScript $script): void
    {
        if ($cleanup->deployment_slug === '') {
            throw new RuntimeException('The preview cleanup has no deployment identifier.');
        }

        $server = $cleanup->server_id ? Server::find($cleanup->server_id) : null;
        if (! $server) {
            return;
        }

        $result = $runner->server($server)->create()->execute($script->render($cleanup));
        if (! $result->isSuccessful()) {
            throw new RuntimeException('Unable to remove the preview stack from its server.');
        }
    }
}
