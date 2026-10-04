<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RestoreDatabaseToPointInTime;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RestoreDatabaseController
{
    /**
     * Restore the database server to a moment, read in UTC, and return to its recovery tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  RestoreDatabaseToPointInTime  $restore
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, RestoreDatabaseToPointInTime $restore): JsonResponse
    {
        $data = $request->validate(['restore_to' => ['required', 'date'], 'confirmation' => ['required', 'string', 'max:64']]);
        $restore->handle($user, $server, CarbonImmutable::parse((string) $data['restore_to'], 'UTC'), (string) $data['confirmation']);

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'recovery'], false), 'message' => __('Restore started. Follow it in the server’s command history.')]);
    }
}
