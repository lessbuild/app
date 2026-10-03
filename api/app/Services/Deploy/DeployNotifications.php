<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Build;
use App\Models\EnvironmentDeployNotification;
use App\Services\Monitoring\AlertDispatcher;
use App\Services\Monitoring\TelemetryRedactor;
use App\Support\Deploy\ReleaseNotes;

/**
 * Tells an environment's chosen alert destinations (Slack, Teams, Discord, webhooks, email) when a deploy goes live,
 * fails, or waits for approval. Deliveries go through the same queue, retries and history as incident alerts.
 */
final class DeployNotifications
{
    /**
     * The events, with the outcome column that turns each on and how it reads.
     *
     * @var array<string, array{column: string, label: string}>
     */
    private const EVENTS = [
        'deploy_succeeded' => ['column' => 'on_success', 'label' => 'Deploy live'],
        'deploy_failed' => ['column' => 'on_failure', 'label' => 'Deploy failed'],
        'deploy_approval' => ['column' => 'on_approval', 'label' => 'Deploy waiting for approval'],
    ];

    /**
     * Create a new DeployNotifications instance.
     *
     * @param  AlertDispatcher  $alerts  Queues the deliveries.
     * @param  TelemetryRedactor  $redactor  Keeps secrets out of commit messages and failure text.
     */
    public function __construct(private readonly AlertDispatcher $alerts, private readonly TelemetryRedactor $redactor) {}

    /**
     * Notify the environment's destinations about a deploy, when they asked to hear about this outcome.
     *
     * @param  Build  $build
     * @param  string  $event  deploy_succeeded, deploy_failed or deploy_approval
     * @return int how many deliveries were queued
     */
    public function send(Build $build, string $event): int
    {
        $build->loadMissing(['environment.project', 'repository', 'requester']);
        $environment = $build->environment;
        $project = $environment?->project;
        if ($environment === null || $project === null || ! isset(self::EVENTS[$event])) {
            return 0;
        }
        $routes = $environment->deployNotifications()->with('destination')->where(self::EVENTS[$event]['column'], true)->get()
            ->filter(fn (EnvironmentDeployNotification $route): bool => $route->destination->enabled && $route->destination->account_id === $project->account_id);
        if ($routes->isEmpty()) {
            return 0;
        }
        $payload = $this->redactor->redact([
            'kind' => 'deploy', 'event' => $event, 'event_label' => self::EVENTS[$event]['label'],
            'title' => __('Deploy #:id of :repository to :environment', ['id' => $build->id, 'repository' => $build->repository->name, 'environment' => $environment->name]),
            'application' => $project->name, 'project' => $project->name, 'environment' => $environment->name,
            'deploy' => [
                'id' => $build->id, 'status' => $build->status, 'revision' => $build->revision, 'commit_message' => $build->commit_message,
                'started_by' => $build->requester?->name, 'trigger' => $build->trigger_source, 'failure' => $build->failure_message,
            ],
        ]);
        $payload['notes'] = $event === 'deploy_succeeded' ? ReleaseNotes::text($build->release_commits ?? []) : null;
        $payload['url'] = route('deploy.builds.show', [$project, $build->id]);
        if ($event === 'deploy_approval') {
            $payload['actions'] = [
                ['label' => __('Approve'), 'url' => route('deploy.builds.decide', [$project, $build->id, 'decision' => 'approve']), 'style' => 'primary'],
                ['label' => __('Reject'), 'url' => route('deploy.builds.decide', [$project, $build->id, 'decision' => 'reject']), 'style' => 'danger'],
            ];
        }
        $payload['url_label'] = __('View deploy');
        foreach ($routes as $route) {
            $this->alerts->queue($route->destination, $payload);
        }

        return $routes->count();
    }
}
