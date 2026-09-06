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

    public function __construct(public readonly int $previewId) {}

    public function uniqueId(): string
    {
        return (string) $this->previewId;
    }

    public function handle(GitHubPreviewReporter $reporter): void
    {
        $preview = PreviewDeployment::find($this->previewId);
        if ($preview) {
            $reporter->report($preview);
        }
    }
}
