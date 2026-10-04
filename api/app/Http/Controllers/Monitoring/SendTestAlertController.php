<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SendTestAlert;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SendTestAlertController
{
    /**
     * Queue a test alert; its outcome appears in the delivery history.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertDestination  $destination
     * @param  SendTestAlert  $send
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AlertDestination $destination, SendTestAlert $send): JsonResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $send->handle($project->account, $user, $destination, $version);

        return response()->json(['redirect' => route('monitoring.destinations.show', [$project, $destination->id], false), 'message' => __('Test notification queued. Its outcome appears below.')]);
    }
}
