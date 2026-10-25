<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\ResizeServer;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResizeServerController
{
    /**
     * Resize a cloud server to a new plan (optionally growing its disk). It powers off, resizes and powers back on in
     * the background; the server shows as resizing meanwhile. Authorisation is the route's can:update,server.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ResizeServer  $resize
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ResizeServer $resize): JsonResponse
    {
        $data = $request->validate([
            'size' => ['required', 'string', 'max:100'],
            'resize_disk' => ['sometimes', 'boolean'],
        ]);
        $resize->handle($user, $server, (string) $data['size'], $request->boolean('resize_disk'));

        return response()->json(['message' => __('Resizing the server. It restarts during the change, which can take a few minutes.')]);
    }
}
