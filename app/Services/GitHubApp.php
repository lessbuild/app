<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubApp
{
    /**
     * Check whether every required GitHub App credential setting is populated.
     *
     * @return bool Whether app ID, slug, private key and webhook secret are present.
     */
    public function configured(): bool
    {
        return filled(config('github-app.id')) && filled(config('github-app.slug'))
            && filled(config('github-app.private_key')) && filled(config('github-app.webhook_secret'));
    }

    /**
     * Construct the GitHub App installation URL after checking the configured slug.
     *
     * @param  string  $state  The caller's state token to include in the installation flow.
     * @return string The GitHub installation URL with encoded state.
     *
     * @throws RuntimeException If the app is incomplete or its slug is invalid.
     */
    public function installationUrl(string $state): string
    {
        $slug = trim((string) config('github-app.slug'));
        if (! $this->configured() || ! preg_match('/\A[a-zA-Z0-9-]+\z/', $slug)) {
            throw new RuntimeException('GitHub App is not configured.');
        }

        return 'https://github.com/apps/'.$slug.'/installations/new?'.http_build_query(['state' => $state]);
    }

    /**
     * Exchange an app JWT for a repository-installation access token.
     *
     * @param  string|int  $installationId  The GitHub installation identifier to authorize.
     * @return string The nonempty token returned by GitHub.
     *
     * @throws RuntimeException If the response omits the installation token.
     */
    public function installationToken(string|int $installationId): string
    {
        $response = Http::acceptJson()->withToken($this->jwt())->withHeader('X-GitHub-Api-Version', '2026-03-10')
            ->post('https://api.github.com/app/installations/'.rawurlencode((string) $installationId).'/access_tokens');

        $token = $response->throw()->json('token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('GitHub did not return an installation token.');
        }

        return $token;
    }

    /** @return list<array{id: int, full_name: string, private: bool, default_branch: string}> */
    public function repositories(string|int $installationId): array
    {
        $response = Http::acceptJson()->withToken($this->installationToken($installationId))->withHeader('X-GitHub-Api-Version', '2026-03-10')
            ->get('https://api.github.com/installation/repositories', ['per_page' => 100])->throw();

        return collect($response->json('repositories', []))->filter(fn ($repository): bool => is_array($repository) && isset($repository['id'], $repository['full_name']))
            ->map(fn (array $repository): array => [
                'id' => (int) $repository['id'],
                'full_name' => (string) $repository['full_name'],
                'private' => (bool) ($repository['private'] ?? false),
                'default_branch' => (string) ($repository['default_branch'] ?? 'main'),
            ])->values()->all();
    }

    /**
     * Publish a preview check run with the deployment's current outcome.
     *
     * @param  string|int  $installationId  The installation authorizing access to the repository.
     * @param  string  $repository  The repository in owner/name form.
     * @param  string  $revision  The commit SHA associated with the check.
     * @param  string  $status  The preview status, mapped to success, failure, cancellation or in-progress.
     * @param  string  $summary  The check output summary shown on GitHub.
     * @param  string|null  $detailsUrl  An optional link to deployment details.
     * @return void No value; sends a check-run creation request and propagates HTTP failures.
     */
    public function createCheck(string|int $installationId, string $repository, string $revision, string $status, string $summary, ?string $detailsUrl = null): void
    {
        $conclusion = match ($status) {
            'ready', 'succeeded' => 'success',
            'failed' => 'failure',
            'closed' => 'cancelled',
            default => null,
        };
        $payload = [
            'name' => 'BuildPusher preview',
            'head_sha' => $revision,
            'status' => $conclusion ? 'completed' : 'in_progress',
            'output' => ['title' => 'BuildPusher preview', 'summary' => $summary],
        ];
        if ($conclusion) {
            $payload['conclusion'] = $conclusion;
        }
        if ($detailsUrl) {
            $payload['details_url'] = $detailsUrl;
        }
        Http::acceptJson()->withToken($this->installationToken($installationId))->withHeader('X-GitHub-Api-Version', '2022-11-28')
            ->post('https://api.github.com/repos/'.$repository.'/check-runs', $payload)->throw();
    }

    /**
     * Update the first marked preview comment among the first 100 comments, or create one.
     *
     * @param  string|int  $installationId  The installation authorizing repository access.
     * @param  string  $repository  The repository in owner/name form.
     * @param  int  $number  The pull-request issue number.
     * @param  string  $body  The Markdown body to append after the BuildPusher marker.
     * @return void No value; sends an issue-comment update or creation request.
     */
    public function upsertPullRequestComment(string|int $installationId, string $repository, int $number, string $body): void
    {
        $client = Http::acceptJson()->withToken($this->installationToken($installationId))->withHeader('X-GitHub-Api-Version', '2022-11-28');
        $marker = '<!-- buildpusher-preview -->';
        $comments = $client->get("https://api.github.com/repos/{$repository}/issues/{$number}/comments", ['per_page' => 100])->throw()->json();
        $existing = collect(is_array($comments) ? $comments : [])->first(fn ($comment) => is_array($comment) && str_contains((string) ($comment['body'] ?? ''), $marker));
        $payload = ['body' => $marker."\n".$body];
        if (isset($existing['id'])) {
            $client->patch('https://api.github.com/repos/'.$repository.'/issues/comments/'.(int) $existing['id'], $payload)->throw();
        } else {
            $client->post("https://api.github.com/repos/{$repository}/issues/{$number}/comments", $payload)->throw();
        }
    }

    /**
     * Sign a short-lived RS256 token with the configured GitHub App private key.
     *
     * @return string The app JWT, backdated by one minute and expiring nine minutes from issuance.
     *
     * @throws RuntimeException If configuration is missing or signing fails.
     */
    private function jwt(): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('GitHub App is not configured.');
        }
        $now = now()->getTimestamp();
        $encoded = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'.$this->base64Url(json_encode([
            'iat' => $now - 60, 'exp' => $now + 540, 'iss' => (string) config('github-app.id'),
        ], JSON_THROW_ON_ERROR));
        $key = str_replace('\\n', "\n", (string) config('github-app.private_key'));
        if (! str_contains($key, 'BEGIN') && is_file($key)) {
            $key = (string) file_get_contents($key);
        }
        if (! openssl_sign($encoded, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the GitHub App request.');
        }

        return $encoded.'.'.$this->base64Url($signature);
    }

    /**
     * Encode bytes using unpadded URL-safe Base64 for JWT segments.
     *
     * @param  string  $value  The raw segment bytes.
     * @return string The encoded segment with URL-safe alphabet and no padding.
     */
    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
