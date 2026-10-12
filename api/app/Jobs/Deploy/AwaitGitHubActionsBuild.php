<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Actions\Deploy\FinishBuild;
use App\Actions\Deploy\RecordBuildRevision;
use App\Models\Build;
use App\Services\Deploy\DeploymentScript;
use App\Services\Deploy\GitHubActionsBuilds;
use App\Services\Infrastructure\RemoteScriptRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Follows a build on GitHub Actions: finds the website's workflow run for the commit, checks it again every
 * {@see self::INTERVAL} seconds while it runs, and when it succeeds starts the release part on the website's server
 * with a fresh link to the artifact. A run that fails, never starts, or takes too long fails the deploy with the reason.
 */
final class AwaitGitHubActionsBuild implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds between checks.
     *
     * @var int
     */
    public const INTERVAL = 15;

    /**
     * How long to wait for a run to start, in minutes, before deciding the workflow isn't there.
     *
     * @var int
     */
    public const START_MINUTES = 10;

    /**
     * How long a build may take on GitHub Actions, in minutes.
     *
     * @var int
     */
    public const FINISH_MINUTES = 60;

    /**
     * Each check is one try; the next is a new job.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Create a new AwaitGitHubActionsBuild instance.
     *
     * @param  int  $buildId  The build waiting for GitHub Actions.
     */
    public function __construct(public readonly int $buildId) {}

    /**
     * Check the build's run once and move it on: wait, fail it, or start the release part.
     *
     * @param  GitHubActionsBuilds  $actions
     * @param  RemoteScriptRunner  $runner
     * @param  DeploymentScript  $script
     * @param  FinishBuild  $finish
     * @param  RecordBuildRevision  $revisions
     * @return void
     */
    public function handle(GitHubActionsBuilds $actions, RemoteScriptRunner $runner, DeploymentScript $script, FinishBuild $finish, RecordBuildRevision $revisions): void
    {
        $build = Build::query()->with(['website.server', 'repository.provider', 'environment'])->find($this->buildId);
        if ($build === null || $build->status !== Build::STATUS_RUNNING || $build->build_phase !== 'actions') {
            return;
        }
        Build::query()->whereKey($build->id)->update(['last_heartbeat_at' => now()]);
        $minutes = (int) ($build->started_at ?? now())->diffInMinutes(now());
        $workflow = $actions->workflowPath($build->repository);
        try {
            if ($build->revision === null) {
                $commit = $actions->commit($build);
                $revisions->handle($build, $commit['sha'], $commit['message']);
                $build->refresh();
            }
            $sha = (string) $build->revision;
            $run = $actions->run($build->repository, $sha);
            if ($run === null) {
                if ($minutes >= self::START_MINUTES) {
                    $finish->handle($build, Build::STATUS_FAILED, __('GitHub Actions didn’t start :workflow for commit :commit within :minutes minutes. Add the workflow from the environment’s settings to the repository, push it, then deploy again.', ['workflow' => $workflow, 'commit' => substr($sha, 0, 7), 'minutes' => self::START_MINUTES]));

                    return;
                }
                $this->note($build, __('Waiting for GitHub Actions to start :workflow for commit :commit…', ['workflow' => $workflow, 'commit' => substr($sha, 0, 7)]));
                self::dispatch($build->id)->delay(self::INTERVAL);

                return;
            }
            if ($run['status'] !== 'completed') {
                if ($minutes >= self::FINISH_MINUTES) {
                    $finish->handle($build, Build::STATUS_FAILED, __('The GitHub Actions build didn’t finish within :minutes minutes: :url', ['minutes' => self::FINISH_MINUTES, 'url' => $run['url']]));

                    return;
                }
                $this->note($build, __('Building on GitHub Actions: :url', ['url' => $run['url']]));
                self::dispatch($build->id)->delay(self::INTERVAL);

                return;
            }
            if ($run['conclusion'] !== 'success') {
                $finish->handle($build, Build::STATUS_FAILED, __('The GitHub Actions build failed (:conclusion). See the run’s log: :url', ['conclusion' => $run['conclusion'] ?? 'unknown', 'url' => $run['url']]));

                return;
            }
            $download = $actions->artifactUrl($build->repository, $run['id']);
            if ($download === null) {
                $finish->handle($build, Build::STATUS_FAILED, __('The GitHub Actions run has no “:artifact” artifact. Update the workflow from the environment’s settings: :url', ['artifact' => GitHubActionsBuilds::ARTIFACT, 'url' => $run['url']]));

                return;
            }
            $server = $build->website->server;
            if ($server === null) {
                $finish->handle($build, Build::STATUS_FAILED, __('The website has no server.'));

                return;
            }
            $this->note($build, __('Built on GitHub Actions: :url', ['url' => $run['url']]));
            if (Build::query()->whereKey($build->id)->where('status', Build::STATUS_RUNNING)->where('build_phase', 'actions')->update(['build_phase' => 'release']) === 0) {
                return;
            }
            $build->refresh();
            $process = $runner->start($server, $script->renderRelease($build, $download, DeploymentScript::SOURCE_GITHUB_ACTIONS), "lessbuild-deployment-{$build->id}");
            Build::query()->whereKey($build->id)->where('status', Build::STATUS_RUNNING)
                ->update(['remote_process_id' => $process['id'], 'remote_process_path' => $process['path'], 'last_heartbeat_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
            $finish->handle($build->refresh(), Build::STATUS_FAILED, str(__('The build on GitHub Actions couldn’t be followed: :error', ['error' => $exception->getMessage()]))->limit(2000)->toString());
        }
    }

    /**
     * Show where the build is in its log, replacing the previous note.
     *
     * @param  Build  $build
     * @param  string  $line
     * @return void
     */
    private function note(Build $build, string $line): void
    {
        if ($build->log !== $line) {
            $build->forceFill(['log' => $line])->save();
        }
    }
}
