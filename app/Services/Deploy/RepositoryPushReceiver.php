<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Actions\Deploy\HandlePullRequestWebhook;
use App\Actions\Deploy\HandleRepositoryWebhook;
use App\Exceptions\InvalidRepositoryWebhook;
use App\Models\Repository;
use Illuminate\Http\Request;

/**
 * The HTTP side of a Git host's repository webhook: verify it, then hand a push to HandleRepositoryWebhook and a pull
 * request to HandlePullRequestWebhook.
 */
final class RepositoryPushReceiver
{
    /**
     * Create a new RepositoryPushReceiver instance.
     *
     * Receives webhooks for a repository.
     *
     * @param  RepositoryWebhookVerifier  $verifier  Checks the signature and reads the event.
     * @param  HandleRepositoryWebhook  $handle  Turns a push into a deploy.
     * @param  HandlePullRequestWebhook  $pullRequests  Turns a pull request into a preview.
     */
    public function __construct(private readonly RepositoryWebhookVerifier $verifier, private readonly HandleRepositoryWebhook $handle, private readonly HandlePullRequestWebhook $pullRequests) {}

    /**
     * Verify a Git host's webhook for a repository and act on it, answering with Deployer's statuses and HTTP codes.
     *
     * @param  Repository  $repository
     * @param  Request  $request
     * @return array{string, int} status and HTTP status code
     */
    public function handle(Repository $repository, Request $request): array
    {
        try {
            $webhook = $this->verifier->verify($repository, $request);
        } catch (InvalidRepositoryWebhook $exception) {
            $code = in_array($exception->getCode(), [404, 413, 422], true) ? $exception->getCode() : 401;

            return [match ($code) {
                404 => 'not_found', 413 => 'payload_too_large', 422 => 'invalid_payload', default => 'unauthorized'
            }, $code];
        }
        if ($webhook->isPreviewEvent()) {
            $status = $this->pullRequests->handle($repository, $webhook);

            return [$status, in_array($status, ['provisioning', 'deploying'], true) ? 202 : 200];
        }
        if (! $webhook->isPush) {
            return ['event_ignored', 200];
        }
        if (! $webhook->matchesBranch) {
            return ['branch_ignored', 200];
        }
        $status = $this->handle->handle($repository, $webhook);

        return [$status, match ($status) {
            'queued', 'pending', 'awaiting_approval' => 202, 'unavailable' => 409, default => 200
        }];
    }
}
