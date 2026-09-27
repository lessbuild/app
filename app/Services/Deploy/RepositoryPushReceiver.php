<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Actions\Deploy\HandleRepositoryWebhook;
use App\Exceptions\InvalidRepositoryWebhook;
use App\Models\Repository;
use Illuminate\Http\Request;

/** The HTTP side of a Git host's push webhook: verify it, then hand the push to HandleRepositoryWebhook. */
final class RepositoryPushReceiver
{
    public function __construct(private readonly RepositoryWebhookVerifier $verifier, private readonly HandleRepositoryWebhook $handle) {}

    /**
     * Verify a Git host's webhook for a repository and act on it, answering with Deployer's statuses and HTTP codes.
     *
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
