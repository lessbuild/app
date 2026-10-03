<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Queries\Infrastructure\WebsiteFilesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowWebsiteFilesController
{
    /**
     * Browse a website's folder on its server: a folder, the end of a file (?file=), or a search of its logs (?q=).
     * The website page's Files tab asks for just the browser (X-Fragment); otherwise it's a page of its own. Only
     * people who may run commands on the server can.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  WebsiteFilesQuery  $files
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, WebsiteFilesQuery $files, ProjectOverviewQuery $overview): View
    {
        abort_unless($website->server !== null && $user->can('runCommands', $website->server), 403);
        $path = trim((string) $request->query('path', ''), '/');
        $file = is_string($request->query('file')) ? trim($request->query('file'), '/') : null;
        $phrase = is_string($request->query('q')) ? $request->query('q') : null;
        $data = [
            'project' => $project,
            'website' => $website,
            'path' => $path,
            'file' => $file,
            'phrase' => $phrase,
            'folder' => $file === null && $phrase === null ? $files->folder($website, $path) : null,
            'tail' => $file !== null ? $files->tail($website, $file) : null,
            'search' => $phrase !== null ? $files->search($website, $phrase) : null,
        ];

        return $request->hasHeader('X-Fragment')
            ? view('infrastructure.website-files._browser', $data)
            : view('infrastructure.website-files.page', ['overview' => $overview->handle($project, $user), ...$data]);
    }
}
