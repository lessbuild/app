<?php

namespace App\Services;

use App\Data\VerifiedRepositoryWebhook;
use App\Exceptions\InvalidRepositoryWebhook;
use App\Models\Provider;
use App\Models\Repository;
use Illuminate\Http\Request;
use JsonException;

class RepositoryWebhookVerifier
{
    public function verify(Repository $repository, Request $request): VerifiedRepositoryWebhook
    {
        $raw = $request->getContent();
        $secret = $repository->webhook_secret;
        if (! $repository->webhook_enabled || ! is_string($secret) || $secret === '') {
            throw new InvalidRepositoryWebhook('Webhook not found.', 404);
        }

        if (strlen($raw) > max(1, (int) config('lessbuild.webhook_max_payload_bytes'))) {
            throw new InvalidRepositoryWebhook('The webhook payload is too large.', 413);
        }

        $repository->loadMissing('provider');
        $provider = $repository->provider?->provider;
        $deliveryId = match ($provider) {
            Provider::TYPE_GITHUB => $this->verifyGitHub($request, $raw, $secret),
            Provider::TYPE_GITLAB => $this->verifyGitLab($request, $raw, $secret),
            Provider::TYPE_BITBUCKET => $this->verifyBitbucket($request, $raw, $secret),
            default => throw new InvalidRepositoryWebhook('Unsupported webhook provider.'),
        };

        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidRepositoryWebhook('The webhook payload is not valid JSON.', 422);
        }

        if (! is_array($payload)) {
            throw new InvalidRepositoryWebhook('The webhook payload is not a JSON object.', 422);
        }

        [$isPush, $matchesBranch] = match ($provider) {
            Provider::TYPE_GITHUB => $this->githubEvent($request, $payload, $repository->branch),
            Provider::TYPE_GITLAB => $this->gitLabEvent($request, $payload, $repository->branch),
            Provider::TYPE_BITBUCKET => $this->bitbucketEvent($request, $payload, $repository->branch),
        };

        return new VerifiedRepositoryWebhook($deliveryId, $isPush, $matchesBranch);
    }

    private function verifyGitHub(Request $request, string $raw, string $secret): string
    {
        $this->verifyHexHmac($request->header('X-Hub-Signature-256'), $raw, $secret);

        return $this->deliveryId($request->header('X-GitHub-Delivery'));
    }

    private function verifyBitbucket(Request $request, string $raw, string $secret): string
    {
        $this->verifyHexHmac($request->header('X-Hub-Signature'), $raw, $secret);

        return $this->deliveryId($request->header('X-Request-UUID'));
    }

    private function verifyGitLab(Request $request, string $raw, string $secret): string
    {
        $deliveryId = $this->deliveryId($request->header('webhook-id'));
        $timestamp = $request->header('webhook-timestamp');
        if (! is_string($timestamp) || ! ctype_digit($timestamp)
            || abs(now()->getTimestamp() - (int) $timestamp) > 300) {
            throw new InvalidRepositoryWebhook('The webhook timestamp is invalid or expired.');
        }

        if (! str_starts_with($secret, 'whsec_')) {
            throw new InvalidRepositoryWebhook('The GitLab signing token is invalid.');
        }

        $key = base64_decode(substr($secret, 6), true);
        if ($key === false || strlen($key) < 16) {
            throw new InvalidRepositoryWebhook('The GitLab signing token is invalid.');
        }

        $expected = 'v1,'.base64_encode(hash_hmac(
            'sha256',
            "{$deliveryId}.{$timestamp}.{$raw}",
            $key,
            true,
        ));
        $signatures = preg_split('/\s+/', trim((string) $request->header('webhook-signature'))) ?: [];
        if (! collect($signatures)->contains(fn (string $signature): bool => hash_equals($expected, $signature))) {
            throw new InvalidRepositoryWebhook('The webhook signature is invalid.');
        }

        return $deliveryId;
    }

    private function verifyHexHmac(?string $received, string $raw, string $secret): void
    {
        if (! is_string($received) || ! preg_match('/^sha256=[a-f0-9]{64}$/D', $received)) {
            throw new InvalidRepositoryWebhook('The webhook signature is invalid.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
        if (! hash_equals($expected, $received)) {
            throw new InvalidRepositoryWebhook('The webhook signature is invalid.');
        }
    }

    private function deliveryId(?string $deliveryId): string
    {
        $deliveryId = trim((string) $deliveryId);
        if ($deliveryId === '' || strlen($deliveryId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $deliveryId)) {
            throw new InvalidRepositoryWebhook('The webhook delivery identifier is invalid.');
        }

        return $deliveryId;
    }

    /** @return array{bool, bool} */
    private function githubEvent(Request $request, array $payload, string $branch): array
    {
        $isPush = $request->header('X-GitHub-Event') === 'push';

        return [$isPush, $isPush && ($payload['ref'] ?? null) === "refs/heads/{$branch}"];
    }

    /** @return array{bool, bool} */
    private function gitLabEvent(Request $request, array $payload, string $branch): array
    {
        $isPush = $request->header('X-Gitlab-Event') === 'Push Hook';

        return [$isPush, $isPush && ($payload['ref'] ?? null) === "refs/heads/{$branch}"];
    }

    /** @return array{bool, bool} */
    private function bitbucketEvent(Request $request, array $payload, string $branch): array
    {
        $isPush = $request->header('X-Event-Key') === 'repo:push';
        $changes = is_array($payload['push']['changes'] ?? null) ? $payload['push']['changes'] : [];
        $matches = collect($changes)->contains(fn (mixed $change): bool => is_array($change)
            && ($change['new']['type'] ?? null) === 'branch'
            && ($change['new']['name'] ?? null) === $branch);

        return [$isPush, $isPush && $matches];
    }
}
