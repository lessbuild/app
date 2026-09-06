<?php

namespace App\Http\Controllers;

use App\Actions\Repository\HandleRepositoryWebhookAction;
use App\Models\Provider;
use App\Models\Repository;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\RepositoryWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubAppWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        RepositoryWebhookController $webhooks,
        RepositoryWebhookVerifier $verifier,
        HandleRepositoryWebhookAction $handle,
        PreviewDeploymentLifecycle $previews,
    ): JsonResponse {
        $raw = $request->getContent();
        abort_if(strlen($raw) > max(1, (int) config('lessbuild.webhook_max_payload_bytes')), 413);
        $secret = (string) config('github-app.webhook_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');
        abort_unless($secret !== '' && preg_match('/\Asha256=[a-f0-9]{64}\z/D', $signature)
            && hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature), 401);
        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 422);
        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['status' => 'ok']);
        }
        $installationId = filter_var($payload['installation']['id'] ?? null, FILTER_VALIDATE_INT);
        $fullName = $payload['repository']['full_name'] ?? null;
        abort_unless($installationId && is_string($fullName), 422);
        $repository = Repository::query()
            ->whereIn('url', [
                'github.com/'.$fullName,
                'github.com/'.$fullName.'.git',
            ])
            ->whereHas('provider', fn ($query) => $query
                ->where('provider', Provider::TYPE_GITHUB)
                ->where('credential_type', 'app')
                ->where('external_id', (string) $installationId))
            ->firstOrFail();

        return $webhooks($request, $repository, $verifier, $handle, $previews);
    }
}
