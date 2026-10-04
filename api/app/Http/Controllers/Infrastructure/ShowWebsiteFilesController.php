<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Queries\Infrastructure\WebsiteFilesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowWebsiteFilesController
{
    /**
     * Read a website's files on its server, read-only: a folder's entries (`?path=`), the end of a text file
     * (`?file=`), or a search of its Laravel logs (`?q=`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  WebsiteFilesQuery  $files
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, WebsiteFilesQuery $files): JsonResponse
    {
        abort_unless($website->server !== null && $user->can('runCommands', $website->server), 403);
        $path = trim((string) $request->query('path', ''), '/');
        $file = is_string($request->query('file')) ? trim($request->query('file'), '/') : null;
        $phrase = is_string($request->query('q')) ? $request->query('q') : null;

        return response()->json([
            'path' => $path,
            'file' => $file,
            'phrase' => $phrase,
            'folder' => $file === null && $phrase === null ? $files->folder($website, $path) : null,
            'tail' => $file !== null ? $files->tail($website, $file) : null,
            'search' => $phrase !== null ? $files->search($website, $phrase) : null,
        ]);
    }
}
