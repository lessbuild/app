<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Data\Deploy\RepositorySnapshot;
use App\Enums\ProviderType;
use App\Models\Provider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reads a repository's files through its Git provider's API, without cloning: the list of paths at a branch (GitHub
 * and GitLab list the whole tree; Bitbucket the top two levels), and single files when asked for. Used to find out
 * what a repository holds before anything is set up.
 */
final class RepositoryFiles
{
    /**
     * The most paths kept from a provider's listing, so a huge repository can't use up memory.
     *
     * @var int
     */
    public const MAX_PATHS = 20000;

    /**
     * Create a new RepositoryFiles instance.
     *
     * @param  GitHubApp  $github  Calls GitHub as an App installation.
     */
    public function __construct(private readonly GitHubApp $github) {}

    /**
     * Open a repository at a branch, tag or commit.
     *
     * @param  Provider  $provider  the Git provider the repository is connected through
     * @param  string  $url  e.g. github.com/acme/shop
     * @param  string  $ref
     * @return RepositorySnapshot
     *
     * @throws RuntimeException when the provider can't be read
     */
    public function open(Provider $provider, string $url, string $ref): RepositorySnapshot
    {
        $name = $this->name($provider, $url);
        try {
            return match ($provider->type) {
                ProviderType::GitHub => $this->gitHub($provider, $name, $ref),
                ProviderType::GitLab => $this->gitLab($provider, $name, $ref),
                ProviderType::Bitbucket => $this->bitbucket($provider, $name, $ref),
                default => throw new RuntimeException(__('This provider’s repositories can’t be read.')),
            };
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(__('The repository couldn’t be read. Check that :provider can still reach it.', ['provider' => $provider->type->label()]), 0, $exception);
        }
    }

    /**
     * Get the repository's owner/name from its URL, for the provider's host.
     *
     * @param  Provider  $provider
     * @param  string  $url
     * @return string
     */
    private function name(Provider $provider, string $url): string
    {
        $host = match ($provider->type) {
            ProviderType::GitHub => 'github.com',
            ProviderType::GitLab => 'gitlab.com',
            default => 'bitbucket.org',
        };
        $path = (string) preg_replace(['#^(https?://)?(www\.)?#i', '#\.git$#i'], '', trim($url, " /\t\n"));
        if (preg_match('#^'.preg_quote($host, '#').'/([\w.-]+(?:/[\w.-]+)+)$#i', $path, $match) !== 1) {
            throw new RuntimeException(__('This repository’s address isn’t one :provider hosts.', ['provider' => $provider->type->label()]));
        }

        return $match[1];
    }

    /**
     * Read a GitHub repository: the whole tree in one call, files through the contents API as raw text.
     *
     * @param  Provider  $provider
     * @param  string  $name
     * @param  string  $ref
     * @return RepositorySnapshot
     */
    private function gitHub(Provider $provider, string $name, string $ref): RepositorySnapshot
    {
        $client = $provider->isGitHubApp() ? $this->github->client((string) $provider->external_id) : Http::acceptJson()->withToken((string) $provider->token)->withHeader('X-GitHub-Api-Version', '2026-03-10');
        $client = $client->timeout(15);
        $tree = $client->get("https://api.github.com/repos/{$name}/git/trees/".rawurlencode($ref), ['recursive' => 1])->throw()->json('tree', []);
        $paths = [];
        foreach (is_array($tree) ? $tree : [] as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null)) {
                $paths[] = $entry['path'];
            }
        }

        return new RepositorySnapshot(array_slice($paths, 0, self::MAX_PATHS), fn (string $path): ?string => $this->text(
            (clone $client)->accept('application/vnd.github.raw+json')->get("https://api.github.com/repos/{$name}/contents/".implode('/', array_map(rawurlencode(...), explode('/', $path))), ['ref' => $ref]),
        ));
    }

    /**
     * Read a GitLab.com repository: the tree page by page (up to the path limit), files raw.
     *
     * @param  Provider  $provider
     * @param  string  $name
     * @param  string  $ref
     * @return RepositorySnapshot
     */
    private function gitLab(Provider $provider, string $name, string $ref): RepositorySnapshot
    {
        $client = Http::acceptJson()->timeout(15)->withHeader('PRIVATE-TOKEN', (string) $provider->token);
        $base = 'https://gitlab.com/api/v4/projects/'.rawurlencode($name).'/repository';
        $paths = [];
        for ($page = 1; $page <= 20 && count($paths) < self::MAX_PATHS; $page++) {
            $response = $client->get("{$base}/tree", ['ref' => $ref, 'recursive' => 'true', 'per_page' => 100, 'page' => $page])->throw();
            foreach ((array) $response->json() as $entry) {
                if (is_array($entry) && is_string($entry['path'] ?? null)) {
                    $paths[] = $entry['path'];
                }
            }
            if ($response->header('X-Next-Page') === '') {
                break;
            }
        }

        return new RepositorySnapshot(array_slice($paths, 0, self::MAX_PATHS), fn (string $path): ?string => $this->text(
            (clone $client)->get("{$base}/files/".rawurlencode($path).'/raw', ['ref' => $ref]),
        ));
    }

    /**
     * Read a Bitbucket repository: the top level and each folder in it (Bitbucket has no whole-tree listing), files raw.
     *
     * @param  Provider  $provider
     * @param  string  $name
     * @param  string  $ref
     * @return RepositorySnapshot
     */
    private function bitbucket(Provider $provider, string $name, string $ref): RepositorySnapshot
    {
        $client = Http::acceptJson()->timeout(15)->withToken((string) $provider->token);
        $base = "https://api.bitbucket.org/2.0/repositories/{$name}/src/".rawurlencode($ref);
        $list = function (string $folder) use ($client, $base): array {
            $entries = (array) $client->get($base.'/'.($folder === '' ? '' : implode('/', array_map(rawurlencode(...), explode('/', $folder))).'/'), ['pagelen' => 100])->throw()->json('values', []);

            return array_values(array_filter(array_map(fn (mixed $entry): ?array => is_array($entry) && is_string($entry['path'] ?? null) ? ['path' => $entry['path'], 'folder' => ($entry['type'] ?? '') === 'commit_directory'] : null, $entries)));
        };
        $paths = [];
        foreach (array_slice($list(''), 0, 200) as $entry) {
            $paths[] = $entry['path'];
            if ($entry['folder'] && count($paths) < 2000) {
                foreach ($list($entry['path']) as $child) {
                    $paths[] = $child['path'];
                }
            }
        }

        return new RepositorySnapshot($paths, fn (string $path): ?string => $this->text(
            (clone $client)->get($base.'/'.implode('/', array_map(rawurlencode(...), explode('/', $path)))),
        ));
    }

    /**
     * Get a file response's text, or null when it's missing, failed or too big to be a manifest.
     *
     * @param  Response  $response
     * @return string|null
     */
    private function text(Response $response): ?string
    {
        return $response->successful() && strlen($response->body()) <= 512 * 1024 ? $response->body() : null;
    }
}
