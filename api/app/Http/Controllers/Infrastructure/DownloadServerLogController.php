<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use Illuminate\Http\Response;

/** `GET /api/app/projects/{project}/infrastructure/servers/{server}/logs/{type}`. */
final class DownloadServerLogController
{
    /**
     * Download the last copy read of one of a server's logs as a text file.
     *
     * @param  Project  $project
     * @param  Server  $server
     * @param  string  $type
     * @return Response
     */
    public function __invoke(Project $project, Server $server, string $type): Response
    {
        $snapshot = ServerLogSnapshot::query()->where('server_id', $server->id)->where('type', $type)->first();
        abort_if($snapshot === null || $snapshot->log === null, 404);

        return response($snapshot->log, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^a-z0-9-]+/i', '-', $server->name).'-'.preg_replace('/[^a-z0-9-]+/i', '-', $type).'.log"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
