<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Environment;
use Illuminate\Http\Response;

/** GET /releases/{token}: an environment's public release notes, when its team has published them. */
final class ShowPublicReleaseNotesController
{
    /**
     * Show the notes of the last 30 deploys that went live, newest first. Only commit subjects are shown, never who
     * made them or the code.
     *
     * @param  string  $token
     * @return Response
     */
    public function __invoke(string $token): Response
    {
        $environment = Environment::query()->where('release_notes_token', $token)->with('project')->firstOrFail();
        $builds = Build::query()->where('environment_id', $environment->id)->whereNotNull('activated_at')->whereNotNull('release_commits')
            ->latest('activated_at')->limit(30)->get(['id', 'activated_at', 'release_commits', 'release_name']);

        return response()->view('deploy.public-release-notes', ['environment' => $environment, 'builds' => $builds])->header('Cache-Control', 'public, max-age=300');
    }
}
