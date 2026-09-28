<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The platform's GitHub App (ported from Deployer): the install link, installation tokens signed with the App's private
 * key, an installation's repositories, and the check runs and pull-request comments previews report with.
 */
class GitHubApp
{
    /**
     * Whether the App's ID, slug, webhook secret and private key are all set.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return filled(config('github-app.id')) && filled(config('github-app.slug')) && filled(config('github-app.webhook_secret')) && $this->privateKey() !== null;
    }

    /**
     * GitHub's page for installing the App, carrying the one-time state.
     *
     * @param  string  $state
     * @return string
     */
    public function installationUrl(string $state): string
    {
        $slug = trim((string) config('github-app.slug'));
        if (! $this->configured() || preg_match('/\A[a-zA-Z0-9-]+\z/', $slug) !== 1) {
            throw new RuntimeException('The GitHub App isn’t configured.');
        }

        return 'https://github.com/apps/'.$slug.'/installations/new?'.http_build_query(['state' => $state]);
    }

    /**
     * A short-lived token for one installation, from GitHub's API.
     *
     * @param  string  $installationId
     * @return string
     */
    public function installationToken(string $installationId): string
    {
        $token = Http::acceptJson()->withToken($this->jwt())->withHeader('X-GitHub-Api-Version', '2026-03-10')
            ->post('https://api.github.com/app/installations/'.rawurlencode($installationId).'/access_tokens')->throw()->json('token');

        return is_string($token) && $token !== '' ? $token : throw new RuntimeException('GitHub didn’t return an installation token.');
    }

    /**
     * The repositories the installation can reach (first 100), with their visibility and default branch.
     *
     * @param  string  $installationId
     * @return list<array{id: int, full_name: string, private: bool, default_branch: string}>
     */
    public function repositories(string $installationId): array
    {
        $repositories = Http::acceptJson()->withToken($this->installationToken($installationId))->withHeader('X-GitHub-Api-Version', '2026-03-10')
            ->get('https://api.github.com/installation/repositories', ['per_page' => 100])->throw()->json('repositories', []);
        $list = [];
        foreach (is_array($repositories) ? $repositories : [] as $repository) {
            if (is_array($repository) && isset($repository['id'], $repository['full_name'])) {
                $list[] = ['id' => (int) $repository['id'], 'full_name' => (string) $repository['full_name'], 'private' => (bool) ($repository['private'] ?? false), 'default_branch' => (string) ($repository['default_branch'] ?? 'main')];
            }
        }

        return $list;
    }

    /**
     * A JSON Web Token signed with the App's private key, valid for nine minutes (backdated one, for clock drift), which
     * GitHub requires to issue installation tokens.
     *
     * @return string
     */
    private function jwt(): string
    {
        $key = $this->privateKey();
        if (! $this->configured() || $key === null) {
            throw new RuntimeException('The GitHub App isn’t configured.');
        }
        $now = now()->getTimestamp();
        $unsigned = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'
            .$this->base64Url(json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => (string) config('github-app.id')], JSON_THROW_ON_ERROR));
        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The GitHub App request couldn’t be signed.');
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    /**
     * The unencrypted RSA key from config (the key itself, or a readable file), or null.
     *
     * @return string|null
     */
    private function privateKey(): ?string
    {
        foreach ([(string) config('github-app.private_key'), (string) config('github-app.private_key_path')] as $source) {
            $contents = str_contains($source, 'BEGIN') ? $source : ($source !== '' && is_file($source) && is_readable($source) ? (string) file_get_contents($source) : '');
            $contents = trim(str_replace(["\r\n", "\r", '\\n'], "\n", $contents));
            $key = $contents === '' ? false : openssl_pkey_get_private($contents);
            if ($key !== false && (openssl_pkey_get_details($key)['type'] ?? null) === OPENSSL_KEYTYPE_RSA) {
                return $contents;
            }
        }

        return null;
    }

    /**
     * Base64 in its URL-safe form without padding, as JWTs use.
     *
     * @param  string  $value
     * @return string
     */
    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
