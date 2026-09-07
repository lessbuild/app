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
    /**
     * Authenticate the raw provider webhook before parsing deployment and preview event details.
     *
     * @param  Repository  $repository  The webhook-enabled source and its signing secret/provider.
     * @param  Request  $request  The incoming request containing raw bytes and signature headers.
     * @return VerifiedRepositoryWebhook The verified delivery identity and normalized push/preview metadata.
     *
     * @throws InvalidRepositoryWebhook If enablement, size, signature, JSON or event revision validation fails.
     */
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

        [$isPush, $matchesBranch, $revision, $commitMessage] = match ($provider) {
            Provider::TYPE_GITHUB => $this->githubEvent($request, $payload, $repository->branch),
            Provider::TYPE_GITLAB => $this->gitLabEvent($request, $payload, $repository->branch),
            Provider::TYPE_BITBUCKET => $this->bitbucketEvent($request, $payload, $repository->branch),
        };

        if ($matchesBranch && $revision === null) {
            throw new InvalidRepositoryWebhook('The webhook commit revision is invalid.', 422);
        }

        [$previewAction, $pullRequestNumber, $pullRequestTitle, $sourceBranch, $previewRevision] = match ($provider) {
            Provider::TYPE_GITHUB => $this->githubPreviewEvent($request, $payload),
            Provider::TYPE_GITLAB => $this->gitLabPreviewEvent($request, $payload),
            Provider::TYPE_BITBUCKET => $this->bitbucketPreviewEvent($request, $payload),
        };
        if ($previewAction !== null && $previewAction !== 'closed' && $previewRevision === null) {
            throw new InvalidRepositoryWebhook('The pull request revision is invalid.', 422);
        }

        return new VerifiedRepositoryWebhook(
            $deliveryId,
            $isPush,
            $matchesBranch,
            $revision ?? $previewRevision,
            $commitMessage,
            $previewAction,
            $pullRequestNumber,
            $pullRequestTitle,
            $sourceBranch,
        );
    }

    /**
     * Verify the GitHub SHA-256 signature and validate its delivery identifier.
     *
     * @param  Request  $request  The request carrying provider-specific signature and delivery headers.
     * @param  string  $raw  The exact request bytes covered by the signature.
     * @param  string  $secret  The repository's configured webhook signing secret.
     * @return string The validated delivery identifier.
     *
     * @throws InvalidRepositoryWebhook If authentication or delivery metadata is invalid.
     */
    private function verifyGitHub(Request $request, string $raw, string $secret): string
    {
        $this->verifyHexHmac($request->header('X-Hub-Signature-256'), $raw, $secret);

        return $this->deliveryId($request->header('X-GitHub-Delivery'));
    }

    /**
     * Verify the Bitbucket SHA-256 signature and validate its delivery identifier.
     *
     * @param  Request  $request  The request carrying provider-specific signature and delivery headers.
     * @param  string  $raw  The exact request bytes covered by the signature.
     * @param  string  $secret  The repository's configured webhook signing secret.
     * @return string The validated delivery identifier.
     *
     * @throws InvalidRepositoryWebhook If authentication or delivery metadata is invalid.
     */
    private function verifyBitbucket(Request $request, string $raw, string $secret): string
    {
        $this->verifyHexHmac($request->header('X-Hub-Signature'), $raw, $secret);

        return $this->deliveryId($request->header('X-Request-UUID'));
    }

    /**
     * Verify the GitLab timestamped signing-token signature and validate its delivery identifier.
     *
     * @param  Request  $request  The request carrying provider-specific signature and delivery headers.
     * @param  string  $raw  The exact request bytes covered by the signature.
     * @param  string  $secret  The repository's configured webhook signing secret.
     * @return string The validated delivery identifier.
     *
     * @throws InvalidRepositoryWebhook If authentication or delivery metadata is invalid.
     */
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

    /**
     * Validate an exact sha256-prefixed hexadecimal HMAC using a constant-time comparison.
     *
     * @param  string|null  $received  The signature header supplied by the provider.
     * @param  string  $raw  The unmodified webhook request body.
     * @param  string  $secret  The repository's webhook signing secret.
     * @return void No value when the signature matches.
     *
     * @throws InvalidRepositoryWebhook If the signature format or digest is invalid.
     */
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

    /**
     * Trim and validate a nonempty, bounded webhook delivery identifier.
     *
     * @param  string|null  $deliveryId  The optional provider delivery header.
     * @return string The trimmed identifier of at most 255 bytes without control characters.
     *
     * @throws InvalidRepositoryWebhook If the identifier is missing or malformed.
     */
    private function deliveryId(?string $deliveryId): string
    {
        $deliveryId = trim((string) $deliveryId);
        if ($deliveryId === '' || strlen($deliveryId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $deliveryId)) {
            throw new InvalidRepositoryWebhook('The webhook delivery identifier is invalid.');
        }

        return $deliveryId;
    }

    /** @return array{bool, bool, ?string, ?string} */
    private function githubEvent(Request $request, array $payload, string $branch): array
    {
        $isPush = $request->header('X-GitHub-Event') === 'push';
        $matches = $isPush
            && ($payload['ref'] ?? null) === "refs/heads/{$branch}"
            && ($payload['deleted'] ?? false) !== true
            && ! $this->isNullRevision($payload['after'] ?? null);

        return [
            $isPush,
            $matches,
            $matches ? $this->revision($payload['after'] ?? null) : null,
            $matches ? $this->commitMessage($payload['head_commit']['message'] ?? null) : null,
        ];
    }

    /** @return array{bool, bool, ?string, ?string} */
    private function gitLabEvent(Request $request, array $payload, string $branch): array
    {
        $isPush = $request->header('X-Gitlab-Event') === 'Push Hook';
        $matches = $isPush
            && ($payload['ref'] ?? null) === "refs/heads/{$branch}"
            && ! $this->isNullRevision($payload['after'] ?? null);
        $revision = $matches ? $this->revision($payload['after'] ?? $payload['checkout_sha'] ?? null) : null;
        $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];
        $commit = collect($commits)->first(fn (mixed $commit): bool => is_array($commit)
            && $this->revision($commit['id'] ?? null) === $revision);

        return [
            $isPush,
            $matches,
            $revision,
            $matches && is_array($commit) ? $this->commitMessage($commit['message'] ?? null) : null,
        ];
    }

    /** @return array{bool, bool, ?string, ?string} */
    private function bitbucketEvent(Request $request, array $payload, string $branch): array
    {
        $isPush = $request->header('X-Event-Key') === 'repo:push';
        $changes = is_array($payload['push']['changes'] ?? null) ? $payload['push']['changes'] : [];
        $change = collect($changes)->first(fn (mixed $change): bool => is_array($change)
            && ($change['new']['type'] ?? null) === 'branch'
            && ($change['new']['name'] ?? null) === $branch);
        $matches = $isPush && is_array($change);

        return [
            $isPush,
            $matches,
            $matches ? $this->revision($change['new']['target']['hash'] ?? null) : null,
            $matches ? $this->commitMessage($change['new']['target']['message'] ?? null) : null,
        ];
    }

    /** @return array{?string, ?int, ?string, ?string, ?string} */
    private function githubPreviewEvent(Request $request, array $payload): array
    {
        if ($request->header('X-GitHub-Event') !== 'pull_request') {
            return [null, null, null, null, null];
        }

        $action = match ($payload['action'] ?? null) {
            'opened', 'reopened', 'synchronize' => 'updated',
            'closed' => 'closed',
            default => null,
        };

        return $this->previewPayload(
            $action,
            $payload['number'] ?? null,
            $payload['pull_request']['title'] ?? null,
            $payload['pull_request']['head']['ref'] ?? null,
            $payload['pull_request']['head']['sha'] ?? null,
        );
    }

    /** @return array{?string, ?int, ?string, ?string, ?string} */
    private function gitLabPreviewEvent(Request $request, array $payload): array
    {
        if ($request->header('X-Gitlab-Event') !== 'Merge Request Hook') {
            return [null, null, null, null, null];
        }
        $attributes = is_array($payload['object_attributes'] ?? null) ? $payload['object_attributes'] : [];
        $action = match ($attributes['action'] ?? null) {
            'open', 'reopen', 'update', 'approved', 'unapproved' => 'updated',
            'close', 'merge' => 'closed',
            default => null,
        };

        return $this->previewPayload(
            $action,
            $attributes['iid'] ?? null,
            $attributes['title'] ?? null,
            $attributes['source_branch'] ?? null,
            $attributes['last_commit']['id'] ?? null,
        );
    }

    /** @return array{?string, ?int, ?string, ?string, ?string} */
    private function bitbucketPreviewEvent(Request $request, array $payload): array
    {
        $event = $request->header('X-Event-Key');
        $action = match ($event) {
            'pullrequest:created', 'pullrequest:updated' => 'updated',
            'pullrequest:fulfilled', 'pullrequest:rejected' => 'closed',
            default => null,
        };
        $pullRequest = is_array($payload['pullrequest'] ?? null) ? $payload['pullrequest'] : [];

        return $this->previewPayload(
            $action,
            $pullRequest['id'] ?? null,
            $pullRequest['title'] ?? null,
            $pullRequest['source']['branch']['name'] ?? null,
            $pullRequest['source']['commit']['hash'] ?? null,
        );
    }

    /** @return array{?string, ?int, ?string, ?string, ?string} */
    private function previewPayload(mixed $action, mixed $number, mixed $title, mixed $branch, mixed $revision): array
    {
        if (! is_string($action) || ! is_numeric($number) || (int) $number < 1) {
            return [null, null, null, null, null];
        }

        $cleanTitle = is_string($title) ? mb_substr(trim($title), 0, 255) : null;
        $cleanBranch = is_string($branch) ? mb_substr(trim($branch), 0, 255) : null;
        if ($cleanBranch === null || $cleanBranch === '' || preg_match('/[\x00-\x1F\x7F]/', $cleanBranch)) {
            return [null, null, null, null, null];
        }

        return [$action, (int) $number, $cleanTitle ?: null, $cleanBranch, $this->revision($revision)];
    }

    /**
     * Normalize a hexadecimal source revision from provider event data.
     *
     * @param  mixed  $revision  The untrusted payload value.
     * @return string|null A lowercase 40- to 64-character revision, or null when invalid.
     */
    private function revision(mixed $revision): ?string
    {
        return is_string($revision) && preg_match('/\A[0-9a-f]{40,64}\z/Di', $revision)
            ? strtolower($revision)
            : null;
    }

    /**
     * Recognize the all-zero revision sentinel used for deleted branches.
     *
     * @param  mixed  $revision  The untrusted payload revision.
     * @return bool True only for an all-zero string between 40 and 64 characters.
     */
    private function isNullRevision(mixed $revision): bool
    {
        return is_string($revision) && preg_match('/\A0{40,64}\z/D', $revision) === 1;
    }

    /**
     * Remove unsafe control characters and bound a provider commit message.
     *
     * @param  mixed  $message  The untrusted message value from the event payload.
     * @return string|null A trimmed message of at most 500 characters, or null for nonstrings and empty results.
     */
    private function commitMessage(mixed $message): ?string
    {
        if (! is_string($message)) {
            return null;
        }

        $message = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message) ?? '');

        return $message === '' ? null : mb_substr($message, 0, 500);
    }
}
