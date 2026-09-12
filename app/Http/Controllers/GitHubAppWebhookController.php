<?php

namespace App\Http\Controllers;

use App\Actions\Repository\HandleRepositoryWebhookAction;
use App\Models\Provider;
use App\Models\Repository;
use App\Services\GitHubAppWebhookVerifier;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\RepositoryWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubAppWebhookController extends Controller
{
    /**
     * Authenticate a bounded GitHub App payload and locate its installation-owned repository.
     *
     * @return JsonResponse A ping acknowledgement or the repository webhook processing result.
     */
    public function __invoke(
        Request $request,
        GitHubAppWebhookVerifier $appWebhook,
        RepositoryWebhookController $webhooks,
        RepositoryWebhookVerifier $verifier,
        HandleRepositoryWebhookAction $handle,
        PreviewDeploymentLifecycle $previews,
    ): JsonResponse {
        $webhook = $appWebhook->verify(
            $request->getContent(),
            $request->header('X-Hub-Signature-256'),
            $request->header('X-GitHub-Event'),
        );
        if ($webhook->isPing) {
            return response()->json(['status' => 'ok']);
        }

        $repository = Repository::query()
            ->whereIn('url', [
                'github.com/'.$webhook->repositoryFullName,
                'github.com/'.$webhook->repositoryFullName.'.git',
            ])
            ->whereHas('provider', fn ($query) => $query
                ->where('provider', Provider::TYPE_GITHUB)
                ->where('credential_type', 'app')
                ->where('external_id', (string) $webhook->installationId))
            ->firstOrFail();

        return $webhooks($request, $repository, $verifier, $handle, $previews);
    }
}
