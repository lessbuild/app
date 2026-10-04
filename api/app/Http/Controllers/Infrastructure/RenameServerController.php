<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RenameServer;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RenameServerController
{
    /**
     * Set or clears the server's display name.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  RenameServer  $rename
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, RenameServer $rename): JsonResponse
    {
        $validated = $request->validate(['display_name' => ['nullable', 'string', 'max:80', 'not_regex:/[\x00-\x1F\x7F]/u']]);
        $rename->handle($project->account, $user, $server, is_string($validated['display_name'] ?? null) ? $validated['display_name'] : null);

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'settings'], false), 'message' => __('Server renamed.')]);
    }
}
