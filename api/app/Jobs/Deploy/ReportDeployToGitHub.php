<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Models\Build;
use App\Models\Preview;
use App\Services\Deploy\GitHubApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Shows a deploy on its GitHub commit (and so on the pull requests that contain it): a check run per environment that
 * says whether the commit is deploying, waiting for approval, live, failed or stopped. Only for repositories connected
 * through the GitHub App; previews report on their pull request themselves.
 */
final class ReportDeployToGitHub implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new ReportDeployToGitHub instance.
     *
     * @param  int  $buildId  The deploy, read again when the job runs so the report is current.
     */
    public function __construct(public readonly int $buildId) {}

    /**
     * Post the deploy's state as a check run. Deploys without a known commit, other Git hosts, repositories outside
     * the App and GitHub's errors are skipped (errors are reported): deploys work without it.
     *
     * @param  GitHubApp  $github
     * @return void
     */
    public function handle(GitHubApp $github): void
    {
        $build = Build::query()->with(['repository.provider', 'environment'])->find($this->buildId);
        $provider = $build?->repository->provider;
        if ($build === null || $build->revision === null || $build->environment === null || $provider === null || ! $provider->isGitHubApp() || $provider->external_id === null
            || preg_match('#\Agithub\.com/([^/]+/[^/]+?)(?:\.git)?\z#i', $build->repository->url, $match) !== 1
            || Preview::query()->where('repository_id', $build->repository_id)->exists()) {
            return;
        }
        $app = (string) config('app.name');
        $environment = $build->environment->name;
        [$conclusion, $summary] = match ($build->status) {
            Build::STATUS_SUCCEEDED => ['success', __('Live on :environment.', ['environment' => $environment])],
            Build::STATUS_FAILED => ['failure', __('The deploy to :environment failed. Open :app for the log.', ['environment' => $environment, 'app' => $app])],
            Build::STATUS_CANCELED => ['cancelled', __('The deploy to :environment was cancelled.', ['environment' => $environment])],
            Build::STATUS_REJECTED => ['cancelled', __('The deploy to :environment was turned down.', ['environment' => $environment])],
            Build::STATUS_AWAITING_APPROVAL => [null, __('Waiting for someone to approve the deploy to :environment.', ['environment' => $environment])],
            default => [null, __('Deploying to :environment.', ['environment' => $environment])],
        };
        try {
            $github->createDeployCheck($provider->external_id, $match[1], $build->revision, $environment, $conclusion,
                $build->status === Build::STATUS_AWAITING_APPROVAL, $summary, route('deploy.builds.show', [$build->repository->project_id, $build->id]));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
