<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Models\Preview;
use App\Services\Deploy\GitHubApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Tells a GitHub pull request about its preview, for repositories connected through the GitHub App: a check run on the
 * revision and one comment kept up to date. Reports for the same preview queued close together collapse into one.
 */
final class ReportPreviewToGitHub implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds a queued report stays the only one for its preview.
     *
     * @var int
     */
    public int $uniqueFor = 30;

    /**
     * Create a new ReportPreviewToGitHub instance.
     *
     * Reports one preview's current state.
     *
     * @param  int  $previewId  The preview, read again when the job runs so the report is current.
     */
    public function __construct(public readonly int $previewId) {}

    /**
     * Get the key that keeps one queued report per preview.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return (string) $this->previewId;
    }

    /**
     * Post the preview's state to its pull request. Other Git hosts, repositories outside the App and GitHub's errors
     * are skipped (errors are reported): the preview works without it.
     *
     * @param  GitHubApp  $github
     * @return void
     */
    public function handle(GitHubApp $github): void
    {
        $preview = Preview::query()->with('sourceRepository.provider')->find($this->previewId);
        $provider = $preview?->sourceRepository->provider;
        if ($preview === null || $provider === null || ! $provider->isGitHubApp() || $provider->external_id === null
            || preg_match('#\Agithub\.com/([^/]+/[^/]+?)(?:\.git)?\z#i', $preview->sourceRepository->url, $match) !== 1) {
            return;
        }
        $page = route('deploy.previews', $preview->project_id);
        $app = (string) config('app.name');
        $summary = match ($preview->status) {
            Preview::STATUS_READY => __('The preview is ready at https://:url', ['url' => $preview->url]),
            Preview::STATUS_FAILED => __('The preview failed. Open :app for the deploy log.', ['app' => $app]),
            Preview::STATUS_CLOSED => __('The preview has been closed.'),
            default => __(':app is preparing the preview.', ['app' => $app]),
        };
        try {
            $github->createCheck($provider->external_id, $match[1], $preview->revision, $preview->status, $summary, $page);
            $github->upsertPullRequestComment($provider->external_id, $match[1], $preview->pull_request_number, '### '.__('Preview')."\n\n{$summary}\n\n[".__('Open in :app', ['app' => $app])."]({$page})");
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
