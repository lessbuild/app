<?php

namespace App\Jobs;

use App\Models\PreviewDeployment;
use App\Services\GitHubPreviewReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReportGitHubPreviewJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 30;

    /**
     * Capture the preview whose latest deployment state will be reported to GitHub.
     *
     * @param  int  $previewId  Preview deployment identifier reloaded before reporting its current state.
     */
    public function __construct(public readonly int $previewId) {}

    /**
     * Coalesce queued instances of this job for the same preview.
     *
     * @return string The preview identifier used by Laravel's unique-job lock.
     */
    public function uniqueId(): string
    {
        return (string) $this->previewId;
    }

    /**
     * Report the current preview state through its GitHub integration when the preview still exists.
     *
     * @param  GitHubPreviewReporter  $reporter  GitHub reporter that publishes the preview's latest deployment state.
     */
    public function handle(GitHubPreviewReporter $reporter): void
    {
        $preview = PreviewDeployment::find($this->previewId);
        if ($preview) {
            $reporter->report($preview);
        }
    }
}
