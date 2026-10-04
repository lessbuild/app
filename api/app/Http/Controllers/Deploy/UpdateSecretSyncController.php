<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RemoveSecretSync;
use App\Jobs\Deploy\RunSecretSync;
use App\Models\Environment;
use App\Models\Project;
use App\Models\SecretSync;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateSecretSyncController
{
    /**
     * Sync a password manager now (POST) or disconnect it (DELETE), and return to the environment's variables.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  int  $sync
     * @param  RemoveSecretSync  $remove
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, int $sync, RemoveSecretSync $remove): JsonResponse
    {
        abort_unless($environment->project_id === $project->id && $user->can('configureDeploy', $environment), 404);
        $secretSync = SecretSync::query()->where('environment_id', $environment->id)->findOrFail($sync);
        if ($request->isMethod('DELETE')) {
            $remove->handle($user, $secretSync);
            $message = __('Disconnected. Its variables stay as ordinary secrets.');
        } else {
            RunSecretSync::dispatch($secretSync->id);
            $message = __('Syncing now.');
        }

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'], false).'#'.'secret-syncs', 'message' => $message]);
    }
}
