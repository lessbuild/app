<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Enums\ProviderType;
use App\Models\Repository;
use App\Services\Deploy\GitHubAppWebhookVerifier;
use App\Services\Deploy\RepositoryPushReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The GitHub App's webhook (`POST /api/github-app/webhook`, Deployer's public contract): routed to the matching repository. */
final class ReceiveGitHubAppWebhookController
{
    /**
     * Verifies a GitHub App webhook, answers pings, and hands pushes to the repository connected through that
     * installation.
     *
     * @param  Request  $request
     * @param  GitHubAppWebhookVerifier  $app
     * @param  RepositoryPushReceiver  $receive
     * @return JsonResponse
     */
    public function __invoke(Request $request, GitHubAppWebhookVerifier $app, RepositoryPushReceiver $receive): JsonResponse
    {
        $webhook = $app->verify($request->getContent(), $request->header('X-Hub-Signature-256'), $request->header('X-GitHub-Event'));
        if ($webhook->isPing) {
            return response()->json(['status' => 'ok']);
        }
        $repository = Repository::query()->with(['provider', 'website.server'])->whereIn('url', ["github.com/{$webhook->repository}", "github.com/{$webhook->repository}.git"])
            ->whereHas('provider', fn ($query) => $query->where('type', ProviderType::GitHub)->where('credential_type', 'app')->where('external_id', $webhook->installationId))->first();
        if ($repository === null) {
            return response()->json(['status' => 'not_found'], 404);
        }

        [$status, $code] = $receive->handle($repository, $request);

        return response()->json(['status' => $status], $code);
    }
}
