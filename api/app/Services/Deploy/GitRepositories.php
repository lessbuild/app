<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Enums\ProviderType;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class GitRepositories
{
    /**
     * Create a new GitRepositories instance.
     *
     * Lists the repositories a Git provider's credential can reach, so people pick one instead of typing its address.
     *
     * @param  GitHubApp  $github  Lists a GitHub App installation's repositories.
     */
    public function __construct(private readonly GitHubApp $github) {}

    /**
     * Determine whether the provider's repositories can be listed: GitHub (token or app), GitLab.com and Bitbucket.
     * A self-hosted GitLab isn't listed (its address is the customer's), so its repositories are typed in.
     *
     * @param  Provider  $provider
     * @return bool
     */
    public function canList(Provider $provider): bool
    {
        return match ($provider->type) {
            ProviderType::GitHub, ProviderType::Bitbucket => true,
            ProviderType::GitLab => $provider->base_url === null,
            default => false,
        };
    }

    /**
     * List up to 100 repositories the provider can reach, most recently updated first, kept for a minute.
     *
     * @param  Provider  $provider
     * @return list<array{name: string, url: string, private: bool, branch: string, updatedAt: string|null}>
     *
     * @throws RuntimeException when the provider can't be listed or refuses the credential
     */
    public function list(Provider $provider): array
    {
        if (! $this->canList($provider)) {
            throw new RuntimeException('This provider’s repositories can’t be listed.');
        }

        /** @var list<array{name: string, url: string, private: bool, branch: string, updatedAt: string|null}> */
        return Cache::remember("git-repositories:{$provider->id}:".md5((string) $provider->updated_at), 60, fn (): array => match (true) {
            $provider->isGitHubApp() => array_map(fn (array $repository): array => [
                'name' => $repository['full_name'], 'url' => 'github.com/'.$repository['full_name'], 'private' => $repository['private'],
                'branch' => $repository['default_branch'], 'updatedAt' => null,
            ], $this->github->repositories((string) $provider->external_id)),
            $provider->type === ProviderType::GitHub => $this->gitHub($provider),
            $provider->type === ProviderType::GitLab => $this->gitLab($provider),
            default => $this->bitbucket($provider),
        });
    }

    /**
     * List a repository's branches (up to 100, the provider's order), kept for a minute, so people pick one instead of
     * typing it. The address must be on the provider's own host, e.g. github.com/acme/shop for a GitHub provider.
     *
     * @param  Provider  $provider
     * @param  string  $url  The repository's address, with or without https:// and .git.
     * @return list<string>
     *
     * @throws RuntimeException when the provider can't be listed, the address isn't on its host, or it refuses
     */
    public function branches(Provider $provider, string $url): array
    {
        $host = match ($provider->type) {
            ProviderType::GitHub => 'github.com',
            ProviderType::GitLab => 'gitlab.com',
            default => 'bitbucket.org',
        };
        $path = preg_replace(['#^(https?://)?(www\.)?#i', '#\.git$#i'], '', trim($url, " /\t\n"));
        if (! $this->canList($provider) || ! is_string($path) || ! preg_match('#^'.preg_quote($host, '#').'/([\w.-]+(?:/[\w.-]+)+)$#i', $path, $match)) {
            throw new RuntimeException('This repository’s branches can’t be listed.');
        }
        $repository = $match[1];

        /** @var list<string> */
        return Cache::remember("git-branches:{$provider->id}:".md5((string) $provider->updated_at.$repository), 60, function () use ($provider, $repository): array {
            if ($provider->isGitHubApp()) {
                return $this->github->branches((string) $provider->external_id, $repository);
            }
            $items = match ($provider->type) {
                ProviderType::GitHub => Http::acceptJson()->timeout(10)->withToken($provider->token)->withHeader('X-GitHub-Api-Version', '2026-03-10')
                    ->get("https://api.github.com/repos/{$repository}/branches", ['per_page' => 100])->throw()->json(),
                ProviderType::GitLab => Http::acceptJson()->timeout(10)->withHeader('PRIVATE-TOKEN', $provider->token)
                    ->get('https://gitlab.com/api/v4/projects/'.rawurlencode($repository).'/repository/branches', ['per_page' => 100])->throw()->json(),
                default => Http::acceptJson()->timeout(10)->withToken($provider->token)
                    ->get("https://api.bitbucket.org/2.0/repositories/{$repository}/refs/branches", ['pagelen' => 100])->throw()->json('values', []),
            };
            $names = [];
            foreach (is_array($items) ? $items : [] as $item) {
                if (is_array($item) && is_string($item['name'] ?? null) && $item['name'] !== '') {
                    $names[] = $item['name'];
                }
            }

            return $names;
        });
    }

    /**
     * List a repository's branches for a person picking one, or none with a reason they can act on when the provider
     * can't list them or refuses (unexpected failures are reported).
     *
     * @param  Provider  $provider
     * @param  string  $url
     * @return array{branches: list<string>, error: string|null}
     */
    public function branchesOrReason(Provider $provider, string $url): array
    {
        try {
            return ['branches' => $this->branches($provider, $url), 'error' => null];
        } catch (Throwable $exception) {
            if (! $exception instanceof RuntimeException) {
                report($exception);
            }

            return ['branches' => [], 'error' => __('The branches couldn’t be listed. Type the branch name instead.')];
        }
    }

    /**
     * List the repositories a GitHub token can reach.
     *
     * @param  Provider  $provider
     * @return list<array{name: string, url: string, private: bool, branch: string, updatedAt: string|null}>
     */
    private function gitHub(Provider $provider): array
    {
        $items = Http::acceptJson()->timeout(10)->withToken($provider->token)->withHeader('X-GitHub-Api-Version', '2026-03-10')
            ->get('https://api.github.com/user/repos', ['per_page' => 100, 'sort' => 'updated', 'affiliation' => 'owner,collaborator,organization_member'])->throw()->json();

        return $this->rows(is_array($items) ? $items : [], fn (array $item): ?array => isset($item['full_name']) ? [
            'name' => (string) $item['full_name'], 'url' => 'github.com/'.$item['full_name'], 'private' => (bool) ($item['private'] ?? false),
            'branch' => (string) ($item['default_branch'] ?? 'main'), 'updatedAt' => $this->date($item['pushed_at'] ?? $item['updated_at'] ?? null),
        ] : null);
    }

    /**
     * List the projects a GitLab.com token is a member of.
     *
     * @param  Provider  $provider
     * @return list<array{name: string, url: string, private: bool, branch: string, updatedAt: string|null}>
     */
    private function gitLab(Provider $provider): array
    {
        $items = Http::acceptJson()->timeout(10)->withHeader('PRIVATE-TOKEN', $provider->token)
            ->get('https://gitlab.com/api/v4/projects', ['membership' => 'true', 'order_by' => 'last_activity_at', 'per_page' => 100, 'simple' => 'true'])->throw()->json();

        return $this->rows(is_array($items) ? $items : [], fn (array $item): ?array => isset($item['path_with_namespace']) ? [
            'name' => (string) $item['path_with_namespace'], 'url' => 'gitlab.com/'.$item['path_with_namespace'], 'private' => ($item['visibility'] ?? 'private') !== 'public',
            'branch' => (string) ($item['default_branch'] ?? 'main'), 'updatedAt' => $this->date($item['last_activity_at'] ?? null),
        ] : null);
    }

    /**
     * List the repositories a Bitbucket token is a member of.
     *
     * @param  Provider  $provider
     * @return list<array{name: string, url: string, private: bool, branch: string, updatedAt: string|null}>
     */
    private function bitbucket(Provider $provider): array
    {
        $items = Http::acceptJson()->timeout(10)->withToken($provider->token)
            ->get('https://api.bitbucket.org/2.0/repositories', ['role' => 'member', 'sort' => '-updated_on', 'pagelen' => 100])->throw()->json('values', []);

        return $this->rows(is_array($items) ? $items : [], fn (array $item): ?array => isset($item['full_name']) ? [
            'name' => (string) $item['full_name'], 'url' => 'bitbucket.org/'.$item['full_name'], 'private' => (bool) ($item['is_private'] ?? true),
            'branch' => (string) (is_array($item['mainbranch'] ?? null) ? ($item['mainbranch']['name'] ?? 'main') : 'main'), 'updatedAt' => $this->date($item['updated_on'] ?? null),
        ] : null);
    }

    /**
     * Turn the provider's items into rows, skipping anything that isn't a repository.
     *
     * @param  array<mixed>  $items
     * @param  callable(array<mixed>): (array{name: string, url: string, private: bool, branch: string, updatedAt: string|null}|null)  $row
     * @return list<array{name: string, url: string, private: bool, branch: string, updatedAt: string|null}>
     */
    private function rows(array $items, callable $row): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (is_array($item) && ($value = $row($item)) !== null) {
                $rows[] = $value;
            }
        }

        return $rows;
    }

    /**
     * Read a provider's timestamp as ISO 8601, or null when it sent none.
     *
     * @param  mixed  $value
     * @return string|null
     */
    private function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }
}
