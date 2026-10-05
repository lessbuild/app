<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Project;
use Illuminate\Http\Response;

/** `GET /api/app/projects/{project}/deploy/builds/{build}/log`. */
final class DownloadBuildLogController
{
    /**
     * Download a deploy's log as a text file.
     *
     * @param  Project  $project
     * @param  Build  $build
     * @return Response
     */
    public function __invoke(Project $project, Build $build): Response
    {
        return response((string) $build->log, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="deploy-'.$build->id.'.log"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
