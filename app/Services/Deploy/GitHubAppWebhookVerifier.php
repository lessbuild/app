<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Data\Deploy\GitHubAppWebhook;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Checks a GitHub App webhook's signature with the App's secret and reads which installation and repository it's for. */
final class GitHubAppWebhookVerifier
{
    public function verify(string $raw, ?string $signature, ?string $event): GitHubAppWebhook
    {
        if (strlen($raw) > max(1, (int) config('deploy.webhook_max_payload_bytes'))) {
            throw new HttpException(413);
        }
        $secret = (string) config('github-app.webhook_secret');
        if ($secret === '' || preg_match('/\Asha256=[a-f0-9]{64}\z/D', (string) $signature) !== 1 || ! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), (string) $signature)) {
            throw new HttpException(401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            throw new HttpException(422);
        }
        if ($event === 'ping') {
            return new GitHubAppWebhook(true);
        }
        $installation = filter_var($payload['installation']['id'] ?? null, FILTER_VALIDATE_INT);
        $repository = $payload['repository']['full_name'] ?? null;
        if ($installation === false || ! is_string($repository)) {
            throw new HttpException(422);
        }

        return new GitHubAppWebhook(false, (string) $installation, strtolower($repository));
    }
}
