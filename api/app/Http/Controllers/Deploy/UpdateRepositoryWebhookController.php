<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SetRepositoryWebhook;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateRepositoryWebhookController
{
    /**
     * Turn push deploys on (showing the new webhook secret once) or off.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  SetRepositoryWebhook  $set
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Repository $repository, SetRepositoryWebhook $set): JsonResponse
    {
        $secret = $set->handle($user, $repository, $request->isMethod('POST'));
        $back = route('deploy.repositories.show', [$project, $repository->id, 'tab' => 'webhook'], false);

        // A new secret is returned this once, with the address for the Git host's webhook.
        return $secret === null
            ? response()->json(['redirect' => $back, 'message' => __('Push deploys are off.')])
            : response()->json(['redirect' => $back, 'message' => __('Push deploys are on. Add the webhook to your repository.'), 'secret' => $secret, 'url' => route('webhooks.repositories.receive', $repository->id)]);
    }
}
