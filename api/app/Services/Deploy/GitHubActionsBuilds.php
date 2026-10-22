<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Builds that run on GitHub Actions instead of a server: the workflow each website needs in its repository, and the
 * GitHub API calls that find the workflow's run for the commit being deployed and the short-lived link to its
 * artifact. The website's server then only downloads and unpacks the result, so a small server never compiles.
 */
class GitHubActionsBuilds
{
    /**
     * The artifact the workflow uploads.
     *
     * @var string
     */
    public const ARTIFACT = 'buildpusher';

    /**
     * The runtimes a GitHub Actions build can produce: their results run as they are, with nothing to install on the
     * server. Python's virtual environments and Docker images stay on the server that builds them.
     *
     * @var list<string>
     */
    public const RUNTIMES = ['php', 'node'];

    /**
     * Create a new GitHubActionsBuilds instance.
     *
     * @param  GitHubApp  $app  Signs installation tokens for repositories connected through the GitHub App.
     */
    public function __construct(private readonly GitHubApp $app) {}

    /**
     * Determine whether a build runs on GitHub Actions: its environment asks for it, the repository is on GitHub and
     * the runtime is one whose result runs as it is.
     *
     * @param  Build  $build
     * @return bool
     */
    public function for(Build $build): bool
    {
        $runtime = $build->environment_payload['runtime']['type'] ?? $build->environment->runtime_type ?? 'php';

        return $build->environment?->github_actions_builds === true && in_array($runtime, self::RUNTIMES, true)
            && $this->repositoryName($build->repository) !== null;
    }

    /**
     * Get a repository's owner/name on GitHub, or null when it isn't on GitHub.
     *
     * @param  Repository  $repository
     * @return string|null
     */
    public function repositoryName(Repository $repository): ?string
    {
        if ($repository->provider?->type !== ProviderType::GitHub || preg_match('#\Agithub\.com/([^/]+/[^/]+?)(?:\.git)?\z#i', $repository->url, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Get where a website's workflow lives in its repository; each website has its own, so a repository that holds
     * several apps (an API and a website, say) builds each one separately.
     *
     * @param  Repository  $repository
     * @return string
     */
    public function workflowPath(Repository $repository): string
    {
        return '.github/workflows/'.$this->workflowFile($repository);
    }

    /**
     * Find the commit a build ships: the commit it was queued with, else the branch, tag or commit asked for, else the
     * tip of the repository's branch.
     *
     * @param  Build  $build
     * @return array{sha: string, message: string}
     */
    public function commit(Build $build): array
    {
        $ref = $build->revision ?? $build->git_ref ?? $build->repository->branch;
        $commit = $this->client($build->repository)->get($this->api($build->repository, 'commits/'.rawurlencode($ref)))->throw()->json();
        $sha = is_array($commit) ? ($commit['sha'] ?? null) : null;
        if (! is_string($sha) || preg_match('/\A[0-9a-f]{40,64}\z/D', $sha) !== 1) {
            throw new RuntimeException("GitHub has no commit for {$ref}.");
        }

        return ['sha' => $sha, 'message' => mb_substr((string) ($commit['commit']['message'] ?? ''), 0, 500)];
    }

    /**
     * Find the website's workflow run for a commit: a successful one when there is one, else the newest; null before
     * any has started.
     *
     * @param  Repository  $repository
     * @param  string  $sha
     * @return array{id: int, status: string, conclusion: string|null, url: string}|null
     */
    public function run(Repository $repository, string $sha): ?array
    {
        $response = $this->client($repository)->get($this->api($repository, 'actions/workflows/'.rawurlencode($this->workflowFile($repository)).'/runs'), ['head_sha' => $sha, 'per_page' => 10]);
        // GitHub doesn't know the workflow until its file has been pushed.
        if ($response->status() === 404) {
            return null;
        }
        $runs = array_values(array_filter((array) $response->throw()->json('workflow_runs', []), 'is_array'));
        $chosen = null;
        foreach ($runs as $run) {
            if (($run['conclusion'] ?? null) === 'success') {
                $chosen = $run;

                break;
            }
            $chosen ??= $run;
        }

        return $chosen === null ? null : [
            'id' => (int) $chosen['id'],
            'status' => (string) ($chosen['status'] ?? 'queued'),
            'conclusion' => is_string($chosen['conclusion'] ?? null) ? $chosen['conclusion'] : null,
            'url' => (string) ($chosen['html_url'] ?? "https://github.com/{$this->repositoryName($repository)}/actions"),
        ];
    }

    /**
     * Get a link to download a run's artifact. GitHub's link works for about a minute, so the deploy asks for it just
     * before the server starts downloading; null when the run uploaded no artifact by that name (or it expired).
     *
     * @param  Repository  $repository
     * @param  int  $runId
     * @return string|null
     */
    public function artifactUrl(Repository $repository, int $runId): ?string
    {
        $artifacts = (array) $this->client($repository)->get($this->api($repository, "actions/runs/{$runId}/artifacts"), ['name' => self::ARTIFACT])->throw()->json('artifacts', []);
        $artifact = collect($artifacts)->first(fn (mixed $artifact): bool => is_array($artifact) && ($artifact['name'] ?? null) === self::ARTIFACT && ! ($artifact['expired'] ?? false));
        if (! is_array($artifact) || ! is_string($artifact['archive_download_url'] ?? null)) {
            return null;
        }
        $response = $this->client($repository)->withOptions(['allow_redirects' => false])->get($artifact['archive_download_url']);
        $location = $response->header('Location');

        return $response->redirect() && $location !== '' ? $location : throw new RuntimeException('GitHub didn’t give a link to download the artifact.');
    }

    /**
     * Ask GitHub to run a finished run's jobs again, on the same commit, so it uploads a fresh artifact.
     *
     * @param  Repository  $repository
     * @param  int  $runId
     * @return void
     */
    public function rerun(Repository $repository, int $runId): void
    {
        // An empty body must be a JSON object: GitHub rejects the `[]` an empty array encodes to.
        $this->client($repository)->withBody('{}', 'application/json')->post($this->api($repository, "actions/runs/{$runId}/rerun"))->throw();
    }

    /**
     * Write the workflow a website needs: on every push to its branch (and by hand), install and build in the
     * deployed subdirectory as the server would, pack the result and upload it as the `buildpusher` artifact.
     *
     * @param  Repository  $repository
     * @return string
     */
    public function workflow(Repository $repository): string
    {
        $environment = $repository->environment;
        $runtime = $environment->runtime_type ?? 'php';
        $root = $repository->deploymentRoot();
        $branch = $this->yamlString($repository->branch);
        $name = $this->yamlString(config('app.name').': '.($repository->website->url ?? $repository->name));
        $directory = $this->yamlString($root);
        $node = $this->yamlString((string) (($runtime === 'node' ? $environment?->runtime_version : null) ?: '22'));
        $buildCommand = trim((string) ($environment->build_command ?? ''));
        $buildCommands = trim((string) $repository->build_commands);
        $start = (string) ($environment->start_command ?? '');
        $steps = [];
        if ($runtime === 'php') {
            $php = $this->yamlString($repository->website->phpVersion());
            $steps[] = "      - uses: shivammathur/setup-php@v2\n        with:\n          php-version: {$php}\n          tools: composer";
            $steps[] = "      - name: Install PHP dependencies\n        run: if [ -f composer.json ]; then composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader; fi";
        }
        $steps[] = "      - uses: actions/setup-node@v4\n        with:\n          node-version: {$node}";
        $install = $runtime === 'php' ? 'if [ ! -f package.json ]; then exit 0; fi'."\n" : '';
        $steps[] = "      - name: Install Node.js dependencies\n        run: |\n".$this->indent($install.<<<'BASH'
            if [ -f pnpm-lock.yaml ]; then corepack enable && pnpm install --frozen-lockfile
            elif [ -f yarn.lock ]; then corepack enable && yarn install --immutable
            elif [ -f package-lock.json ]; then npm ci --no-audit --no-fund
            else npm install --no-audit --no-fund
            fi
            BASH, 10);
        $build = $buildCommand !== '' ? $buildCommand : ($runtime === 'php' ? 'if [ -f package.json ]; then npm run build --if-present; fi' : 'npm run build --if-present');
        $steps[] = "      - name: Build\n        run: |\n".$this->indent($build, 10);
        if ($buildCommands !== '') {
            $steps[] = "      - name: Build commands\n        run: |\n".$this->indent($buildCommands, 10);
        }
        // A Nuxt-style start command runs a self-contained .output folder; anything else ships the folder with its
        // production dependencies, leaving out Git and (for PHP, whose assets are built by now) node_modules.
        $pack = match (true) {
            $runtime === 'node' && str_contains($start, '.output/') => 'tar --create --gzip --file "$RUNNER_TEMP/buildpusher.tar.gz" .output',
            $runtime === 'node' => "if [ -f package-lock.json ]; then npm prune --omit=dev; fi\ntar --create --gzip --file \"\$RUNNER_TEMP/buildpusher.tar.gz\" --exclude=./.git .",
            default => 'tar --create --gzip --file "$RUNNER_TEMP/buildpusher.tar.gz" --exclude=./.git --exclude=./node_modules .',
        };
        $steps[] = "      - name: Pack the release\n        run: |\n".$this->indent($pack, 10);
        $steps[] = "      - uses: actions/upload-artifact@v4\n        with:\n          name: ".self::ARTIFACT."\n          path: \${{ runner.temp }}/buildpusher.tar.gz\n          retention-days: 7\n          compression-level: 0";
        $steps = implode("\n", $steps);

        return <<<YAML
        # Builds this website for {$this->yamlComment(config('app.name'))}: it waits for this workflow's run on the commit it deploys,
        # downloads the "buildpusher" artifact and makes it live on your server. Variables the build needs go in the
        # repository's Actions secrets and variables; the server's variables are added when it goes live.
        name: {$name}

        on:
          push:
            branches: [{$branch}]
          workflow_dispatch:

        jobs:
          build:
            runs-on: ubuntu-latest
            defaults:
              run:
                working-directory: {$directory}
            steps:
              - uses: actions/checkout@v4
        {$steps}

        YAML;
    }

    /**
     * Get the workflow's file name, from the website's directory name on the server.
     *
     * @param  Repository  $repository
     * @return string
     */
    private function workflowFile(Repository $repository): string
    {
        return 'buildpusher-'.$repository->website->deployment_slug.'.yml';
    }

    /**
     * Get a GitHub API client for a repository's provider: the GitHub App's installation token, or the saved token.
     *
     * @param  Repository  $repository
     * @return PendingRequest
     */
    public function client(Repository $repository): PendingRequest
    {
        $provider = $repository->provider ?? throw new RuntimeException('The repository has no Git provider.');
        $token = $provider->isGitHubApp() ? $this->app->installationToken((string) $provider->external_id) : (string) $provider->token;

        return Http::acceptJson()->timeout(15)->withToken($token)->withHeader('X-GitHub-Api-Version', '2026-03-10');
    }

    /**
     * Build an API address under the repository.
     *
     * @param  Repository  $repository
     * @param  string  $path
     * @return string
     */
    public function api(Repository $repository, string $path): string
    {
        $name = $this->repositoryName($repository) ?? throw new RuntimeException('The repository isn’t on GitHub.');

        return "https://api.github.com/repos/{$name}/{$path}";
    }

    /**
     * Quote a value for YAML.
     *
     * @param  string  $value
     * @return string
     */
    private function yamlString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Keep a value on one comment line.
     *
     * @param  mixed  $value
     * @return string
     */
    private function yamlComment(mixed $value): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $value);
    }

    /**
     * Indent each line of a block of shell for a YAML literal.
     *
     * @param  string  $text
     * @param  int  $spaces
     * @return string
     */
    private function indent(string $text, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);

        return implode("\n", array_map(fn (string $line): string => $line === '' ? '' : $pad.$line, preg_split('/\R/', trim($text)) ?: []));
    }
}
