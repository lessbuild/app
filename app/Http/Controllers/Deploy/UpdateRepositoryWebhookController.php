<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SetRepositoryWebhook;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateRepositoryWebhookController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Repository $repository, SetRepositoryWebhook $set): RedirectResponse
    {
        $secret = $set->handle($user, $repository, $request->isMethod('POST'));
        $redirect = to_route('deploy.repositories.show', [$project, $repository->id, 'tab' => 'webhook']);

        return $secret === null
            ? $redirect->with('status', __('Push deploys are off.'))
            : $redirect->with('secrets', ['webhook' => $secret])->with('status', __('Push deploys are on. Add the webhook to your repository.'));
    }
}
