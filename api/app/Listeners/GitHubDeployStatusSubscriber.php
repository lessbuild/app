<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Deploy\DeployAwaitingApproval;
use App\Events\Deploy\DeployFinished;
use App\Events\Deploy\DeployStarted;
use App\Jobs\Deploy\ReportDeployToGitHub;
use App\Services\Deploy\GitHubApp;
use Illuminate\Events\Dispatcher;

/** Keeps a deploy's GitHub check run current as it starts, waits for approval and finishes. */
final class GitHubDeployStatusSubscriber
{
    /**
     * Create a new GitHubDeployStatusSubscriber instance.
     *
     * @param  GitHubApp  $github  Says whether a GitHub App is set up at all.
     */
    public function __construct(private readonly GitHubApp $github) {}

    /**
     * Register the events this subscriber handles.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [DeployStarted::class => 'report', DeployAwaitingApproval::class => 'report', DeployFinished::class => 'report'];
    }

    /**
     * Queue a report of the deploy's state to GitHub, when a GitHub App is set up.
     *
     * @param  DeployStarted|DeployAwaitingApproval|DeployFinished  $event
     * @return void
     */
    public function report(DeployStarted|DeployAwaitingApproval|DeployFinished $event): void
    {
        if ($this->github->configured()) {
            ReportDeployToGitHub::dispatch($event->build->id);
        }
    }
}
