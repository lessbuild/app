<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubApp
{
    public function configured(): bool
    {
        return filled(config('github-app.id')) && filled(config('github-app.slug'))
            && filled(config('github-app.private_key')) && filled(config('github-app.webhook_secret'));
    }

    public function installationUrl(string $state): string
    {
        $slug = trim((string) config('github-app.slug'));
        if (! $this->configured() || ! preg_match('/\A[a-zA-Z0-9-]+\z/', $slug)) {
            throw new RuntimeException('GitHub App is not configured.');
        }

        return 'https://github.com/apps/'.$slug.'/installations/new?'.http_build_query(['state' => $state]);
    }

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

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
