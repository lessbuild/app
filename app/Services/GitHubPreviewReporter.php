<?php

namespace App\Services;

use App\Models\PreviewDeployment;
use Throwable;

class GitHubPreviewReporter
{
    public function __construct(private readonly GitHubApp $github) {}

    public function report(PreviewDeployment $preview): void
    {
        $preview->loadMissing('sourceRepository.provider');
        $source = $preview->sourceRepository;
        $provider = $source?->provider;
        if (! $provider?->isGitHubApp() || ! preg_match('#\Agithub\.com/([^/]+/[^/]+?)(?:\.git)?\z#i', (string) $source->url, $match)) {
            return;
        }
        $repository = $match[1];
        $summary = match ($preview->status) {
            PreviewDeployment::STATUS_READY => 'Preview is ready at https://'.$preview->url,
            PreviewDeployment::STATUS_FAILED => 'Preview deployment failed. Open BuildPusher to inspect the deployment log.',
            PreviewDeployment::STATUS_CLOSED => 'Preview environment has been closed.',
            default => 'BuildPusher is preparing an isolated preview environment.',
        };
        try {
            if (preg_match('/\A[0-9a-f]{40,64}\z/D', $preview->revision)) {
                $this->github->createCheck($provider->external_id, $repository, $preview->revision, $preview->status, $summary, route('projects.show', $preview->project_id));
            }
            $this->github->upsertPullRequestComment($provider->external_id, $repository, $preview->pull_request_number, "### Preview environment\n\n{$summary}\n\n[Open in BuildPusher](".route('projects.show', $preview->project_id).')');
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
