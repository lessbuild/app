<?php

namespace App\Services;

use App\Data\GitHubAppWebhookData;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GitHubAppWebhookVerifier
{
    /**
     * Verify the bounded raw GitHub App payload before decoding or resolving its repository.
     *
     * @param  string  $raw  The exact request bytes covered by the HMAC signature.
     * @param  string|null  $signature  The X-Hub-Signature-256 header.
     * @param  string|null  $event  The X-GitHub-Event header.
     * @return GitHubAppWebhookData The ping outcome or validated installation/repository identity.
     *
     * @throws HttpException When payload size, signature, JSON or installation metadata is invalid.
     */
    public function verify(string $raw, ?string $signature, ?string $event): GitHubAppWebhookData
    {
        if (strlen($raw) > max(1, (int) config('lessbuild.webhook_max_payload_bytes'))) {
            throw new HttpException(413);
        }

        $secret = (string) config('github-app.webhook_secret');
        $signature = (string) $signature;
        if ($secret === '' || ! preg_match('/\Asha256=[a-f0-9]{64}\z/D', $signature)
            || ! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature)) {
            throw new HttpException(401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            throw new HttpException(422);
        }

        if ($event === 'ping') {
            return new GitHubAppWebhookData(true);
        }

        $installationId = filter_var($payload['installation']['id'] ?? null, FILTER_VALIDATE_INT);
        $fullName = $payload['repository']['full_name'] ?? null;
        if (! $installationId || ! is_string($fullName)) {
            throw new HttpException(422);
        }

        return new GitHubAppWebhookData(false, (int) $installationId, $fullName);
    }
}
