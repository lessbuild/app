<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\ScanDisk;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ScanServerDiskController
{
    /**
     * Scan the server's disk, or clear one category and scan again, and return to its diagnostics.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ScanDisk  $scan
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ScanDisk $scan): JsonResponse
    {
        $category = $request->input('clean');
        $scan->handle($user, $server, is_string($category) && $category !== '' ? $category : null);

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'diagnostics'], false).'#'.'disk', 'message' => is_string($category) && $category !== '' ? __('Clearing, then measuring again.') : __('Measuring the disk.')]);
    }
}
