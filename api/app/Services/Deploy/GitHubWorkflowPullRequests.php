<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Repository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Keeps a repository's GitHub Actions build workflow in step with what Buildpusher needs: whether the file on its
 * branch is missing, out of date or current, and a pull request that adds or updates it (on a branch of its own,
 * reset to the repository's branch each time), instead of people copying the file by hand.
 */
final class GitHubWorkflowPullRequests
{
    /**
     * Create a new GitHubWorkflowPullRequests instance.
     *
     * @param  GitHubActionsBuilds  $actions  Writes the workflow and calls GitHub for the repository.
     */
    public function __construct(private readonly GitHubActionsBuilds $actions) {}

    /**
     * Compare the workflow on the repository's branch with the one it needs: missing, outdated, current, or unknown
     * when GitHub can't be read. Cached for five minutes unless asked to look now.
     *
     * @param  Repository  $repository
     * @param  bool  $fresh  look now, skipping the cache
     * @return string
     */
    public function status(Repository $repository, bool $fresh = false): string
    {
        if ($this->actions->repositoryName($repository) === null) {
            return 'unknown';
        }
        $wanted = trim($this->actions->workflow($repository));
        $key = 'github-workflow:'.$repository->id.':'.md5($wanted.$repository->branch);
        $look = function () use ($repository, $wanted): string {
            try {
                $response = $this->actions->client($repository)->accept('application/vnd.github.raw+json')
                    ->get($this->actions->api($repository, 'contents/'.$this->actions->workflowPath($repository)), ['ref' => $repository->branch]);
            } catch (Throwable) {
                return 'unknown';
            }

            return match (true) {
                $response->status() === 404 => 'missing',
                ! $response->successful() => 'unknown',
                trim($response->body()) === $wanted => 'current',
                default => 'outdated',
            };
        };
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(5), $look);
    }

    /**
     * Open a pull request that adds or updates the workflow, or bring the open one up to date; returns its address.
     *
     * @param  Repository  $repository
     * @return string
     *
     * @throws RuntimeException when GitHub won't let it, saying what permission is missing
     */
    public function open(Repository $repository): string
    {
        $name = $this->actions->repositoryName($repository) ?? throw new RuntimeException(__('The repository isn’t on GitHub.'));
        $client = $this->actions->client($repository);
        $api = fn (string $path): string => $this->actions->api($repository, $path);
        $path = $this->actions->workflowPath($repository);
        $base = $repository->branch;
        $branch = 'buildpusher/workflow-'.(Str::slug((string) $repository->website->deployment_slug) ?: (string) $repository->id);
        try {
            $sha = (string) $client->get($api('git/ref/heads/'.$base))->throw()->json('object.sha');
            $existing = $client->get($api('git/ref/heads/'.$branch));
            // The branch starts from the repository's branch each time, so the pull request only ever holds the workflow.
            $existing->status() === 404
                ? $client->post($api('git/refs'), ['ref' => 'refs/heads/'.$branch, 'sha' => $sha])->throw()
                : $client->patch($api('git/refs/heads/'.$branch), ['sha' => $sha, 'force' => true])->throw();
            $file = $client->get($api('contents/'.$path), ['ref' => $branch]);
            $client->put($api('contents/'.$path), array_filter([
                'message' => $file->successful() ? 'Update the Buildpusher build workflow' : 'Add the Buildpusher build workflow',
                'content' => base64_encode($this->actions->workflow($repository)), 'branch' => $branch,
                'sha' => $file->successful() ? $file->json('sha') : null,
            ]))->throw();
            $owner = Str::before($name, '/');
            $open = $client->get($api('pulls'), ['state' => 'open', 'head' => $owner.':'.$branch])->throw()->json('0.html_url');
            $url = is_string($open) ? $open : (string) $client->post($api('pulls'), [
                'title' => 'Build on GitHub Actions for Buildpusher', 'head' => $branch, 'base' => $base,
                'body' => "Adds `{$path}`, which builds each commit on GitHub Actions so the server only unpacks the result.\n\nMerge it, and Buildpusher's next deploy uses it.",
            ])->throw()->json('html_url');
        } catch (RequestException $exception) {
            throw new RuntimeException(in_array($exception->response->status(), [401, 403, 404, 422], true)
                ? __('GitHub didn’t let Buildpusher open the pull request. Give its GitHub App (or token) permission to write contents and pull requests on :repository.', ['repository' => $name])
                : __('GitHub didn’t answer. Try again in a minute.'), 0, $exception);
        }
        $repository->forceFill(['workflow_pull_request_url' => $url])->save();
        $this->status($repository, true);

        return $url;
    }
}
