<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\Repository\HandleRepositoryWebhookAction;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Services\GitHubAppWebhookVerifier;
use App\Modules\Deployer\Services\PreviewDeploymentLifecycle;
use App\Modules\Deployer\Services\RepositoryWebhookVerifier;
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
