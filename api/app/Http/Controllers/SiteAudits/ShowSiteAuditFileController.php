<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteAudits;

use App\Models\Project;
use App\Models\SiteAuditRun;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** `GET /api/app/projects/{project}/audit/runs/{siteAuditRun}/files/{file}`. */
final class ShowSiteAuditFileController
{
    /**
     * Send one of a run's screenshots or mock-ups, privately cached.
     *
     * @param  Project  $project
     * @param  SiteAuditRun  $siteAuditRun
     * @param  string  $file
     * @return StreamedResponse
     */
    public function __invoke(Project $project, SiteAuditRun $siteAuditRun, string $file): StreamedResponse
    {
        $path = $siteAuditRun->storageDirectory().'/'.$file;
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $file, [
            'Content-Type' => str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
