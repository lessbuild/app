<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Accounts\InvitationAccepted;
use App\Events\Accounts\MemberRemoved;
use App\Events\Billing\ServiceTierChanged;
use App\Events\Deploy\DeployAwaitingApproval;
use App\Events\Deploy\DeployFinished;
use App\Events\Deploy\DeployStarted;
use App\Events\Infrastructure\WebsiteProvisioned;
use App\Events\Infrastructure\WebsiteProvisioningFailed;
use App\Events\Projects\DomainVerified;
use App\Events\Projects\EnvironmentCreated;
use App\Events\Projects\ProjectCreated;
use App\Events\Projects\ProjectDeleted;
use App\Models\Build;
use App\Models\Incident;
use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\Server;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Services\Webhooks\Webhooks;
use Illuminate\Events\Dispatcher;

/** Turns what happens in the platform into webhook events for the account's endpoints. */
final class WebhookSubscriber
{
    /**
     * Create a new WebhookSubscriber instance.
     *
     * @param  Webhooks  $webhooks  Queues the deliveries.
     */
    public function __construct(private readonly Webhooks $webhooks) {}

    /**
     * Register the listeners: platform events, plus model changes for incidents, servers, backups and findings.
     *
     * @param  Dispatcher  $events
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            DeployStarted::class => 'deployStarted',
            DeployFinished::class => 'deployFinished',
            DeployAwaitingApproval::class => 'deployAwaitingApproval',
            WebsiteProvisioned::class => 'websiteReady',
            WebsiteProvisioningFailed::class => 'websiteFailed',
            ProjectCreated::class => 'projectCreated',
            ProjectDeleted::class => 'projectDeleted',
            EnvironmentCreated::class => 'environmentCreated',
            DomainVerified::class => 'domainVerified',
            InvitationAccepted::class => 'memberJoined',
            MemberRemoved::class => 'memberRemoved',
            ServiceTierChanged::class => 'planChanged',
            'eloquent.created: '.Incident::class => 'incidentCreated',
            'eloquent.updated: '.Incident::class => 'incidentUpdated',
            'eloquent.created: '.Server::class => 'serverCreated',
            'eloquent.updated: '.Server::class => 'serverUpdated',
            'eloquent.deleted: '.Server::class => 'serverDeleted',
            'eloquent.updated: '.WebsiteBackup::class => 'backupUpdated',
            'eloquent.created: '.SecurityFinding::class => 'findingCreated',
        ];
    }

    /**
     * Send deploy.started.
     *
     * @param  DeployStarted  $event
     * @return void
     */
    public function deployStarted(DeployStarted $event): void
    {
        $this->build('deploy.started', $event->build);
    }

    /**
     * Send deploy.succeeded or deploy.failed.
     *
     * @param  DeployFinished  $event
     * @return void
     */
    public function deployFinished(DeployFinished $event): void
    {
        $this->build($event->build->status === Build::STATUS_SUCCEEDED ? 'deploy.succeeded' : 'deploy.failed', $event->build);
    }

    /**
     * Send deploy.awaiting_approval.
     *
     * @param  DeployAwaitingApproval  $event
     * @return void
     */
    public function deployAwaitingApproval(DeployAwaitingApproval $event): void
    {
        $this->build('deploy.awaiting_approval', $event->build);
    }

    /**
     * Send website.ready.
     *
     * @param  WebsiteProvisioned  $event
     * @return void
     */
    public function websiteReady(WebsiteProvisioned $event): void
    {
        $this->webhooks->dispatch($event->website->account_id, 'website.ready', $this->website($event->website));
    }

    /**
     * Send website.failed.
     *
     * @param  WebsiteProvisioningFailed  $event
     * @return void
     */
    public function websiteFailed(WebsiteProvisioningFailed $event): void
    {
        $this->webhooks->dispatch($event->website->account_id, 'website.failed', $this->website($event->website));
    }

    /**
     * Send project.created.
     *
     * @param  ProjectCreated  $event
     * @return void
     */
    public function projectCreated(ProjectCreated $event): void
    {
        $this->webhooks->dispatch($event->project->account_id, 'project.created', ['project' => $this->project($event->project), 'actor' => $event->actor->email]);
    }

    /**
     * Send project.deleted.
     *
     * @param  ProjectDeleted  $event
     * @return void
     */
    public function projectDeleted(ProjectDeleted $event): void
    {
        $this->webhooks->dispatch($event->accountId, 'project.deleted', ['project' => ['id' => $event->projectId, 'name' => $event->name], 'actor' => $event->actor->email]);
    }

    /**
     * Send environment.created.
     *
     * @param  EnvironmentCreated  $event
     * @return void
     */
    public function environmentCreated(EnvironmentCreated $event): void
    {
        $environment = $event->environment;
        $project = $environment->project;
        $this->webhooks->dispatch($project->account_id, 'environment.created', [
            'environment' => ['id' => $environment->id, 'name' => $environment->name, 'slug' => $environment->slug], 'project' => $this->project($project), 'actor' => $event->actor->email,
        ]);
    }

    /**
     * Send domain.verified.
     *
     * @param  DomainVerified  $event
     * @return void
     */
    public function domainVerified(DomainVerified $event): void
    {
        $project = Project::query()->find($event->domain->project_id);
        if ($project !== null) {
            $this->webhooks->dispatch($project->account_id, 'domain.verified', ['domain' => ['id' => $event->domain->id, 'hostname' => $event->domain->hostname], 'project' => $this->project($project)]);
        }
    }

    /**
     * Send member.joined.
     *
     * @param  InvitationAccepted  $event
     * @return void
     */
    public function memberJoined(InvitationAccepted $event): void
    {
        $membership = $event->membership;
        $this->webhooks->dispatch($membership->account_id, 'member.joined', ['member' => ['email' => $membership->user->email, 'role' => $membership->role->value]]);
    }

    /**
     * Send member.removed.
     *
     * @param  MemberRemoved  $event
     * @return void
     */
    public function memberRemoved(MemberRemoved $event): void
    {
        $this->webhooks->dispatch($event->account->id, 'member.removed', ['member' => ['email' => $event->member->email, 'role' => $event->role->value], 'actor' => $event->actor->email]);
    }

    /**
     * Send billing.plan_changed.
     *
     * @param  ServiceTierChanged  $event
     * @return void
     */
    public function planChanged(ServiceTierChanged $event): void
    {
        $this->webhooks->dispatch($event->account->id, 'billing.plan_changed', ['service' => $event->service, 'from' => $event->from, 'to' => $event->to, 'actor' => $event->actor?->email]);
    }

    /**
     * Send incident.opened.
     *
     * @param  Incident  $incident
     * @return void
     */
    public function incidentCreated(Incident $incident): void
    {
        $this->webhooks->dispatch($incident->account_id, 'incident.opened', ['incident' => $this->incident($incident)]);
    }

    /**
     * Send incident.resolved when an incident's resolution is recorded.
     *
     * @param  Incident  $incident
     * @return void
     */
    public function incidentUpdated(Incident $incident): void
    {
        if ($incident->wasChanged('resolved_at') && $incident->resolved_at !== null) {
            $this->webhooks->dispatch($incident->account_id, 'incident.resolved', ['incident' => $this->incident($incident)]);
        }
    }

    /**
     * Send server.created.
     *
     * @param  Server  $server
     * @return void
     */
    public function serverCreated(Server $server): void
    {
        $this->webhooks->dispatch($server->account_id, 'server.created', ['server' => $this->server($server)]);
    }

    /**
     * Send server.ready or server.failed when provisioning ends.
     *
     * @param  Server  $server
     * @return void
     */
    public function serverUpdated(Server $server): void
    {
        if (! $server->wasChanged('provisioning_status')) {
            return;
        }
        $event = match ($server->provisioning_status) {
            Server::STATUS_ACTIVE => 'server.ready',
            Server::STATUS_FAILED => 'server.failed',
            default => null,
        };
        if ($event !== null) {
            $this->webhooks->dispatch($server->account_id, $event, ['server' => $this->server($server)]);
        }
    }

    /**
     * Send server.deleted.
     *
     * @param  Server  $server
     * @return void
     */
    public function serverDeleted(Server $server): void
    {
        $this->webhooks->dispatch($server->account_id, 'server.deleted', ['server' => $this->server($server)]);
    }

    /**
     * Send backup.succeeded or backup.failed when a backup ends.
     *
     * @param  WebsiteBackup  $backup
     * @return void
     */
    public function backupUpdated(WebsiteBackup $backup): void
    {
        if (! $backup->wasChanged('status') || ! in_array($backup->status, ['succeeded', 'failed'], true)) {
            return;
        }
        $website = Website::withTrashed()->find($backup->website_id);
        if ($website !== null) {
            $this->webhooks->dispatch($website->account_id, 'backup.'.$backup->status, ['backup' => ['id' => $backup->id, 'status' => $backup->status], 'website' => $this->website($website)]);
        }
    }

    /**
     * Send security.finding for a new finding.
     *
     * @param  SecurityFinding  $finding
     * @return void
     */
    public function findingCreated(SecurityFinding $finding): void
    {
        $project = Project::query()->find($finding->project_id);
        if ($project !== null) {
            $this->webhooks->dispatch($project->account_id, 'security.finding', [
                'finding' => ['id' => $finding->id, 'source' => $finding->source, 'severity' => $finding->severity, 'title' => $finding->title, 'subject' => $finding->subject, 'fix' => $finding->fix],
                'project' => $this->project($project),
            ]);
        }
    }

    /**
     * Send a deploy event with the build, its environment and project.
     *
     * @param  string  $event
     * @param  Build  $build
     * @return void
     */
    private function build(string $event, Build $build): void
    {
        $website = Website::withTrashed()->find($build->website_id);
        if ($website === null) {
            return;
        }
        $environment = $build->environment;
        $project = $environment?->project;
        $this->webhooks->dispatch($website->account_id, $event, [
            'deploy' => [
                'id' => $build->id, 'status' => $build->status, 'trigger' => $build->trigger_source, 'revision' => $build->revision,
                'commit_message' => $build->commit_message, 'failure_message' => $build->failure_message,
            ],
            'environment' => $environment === null ? null : ['id' => $environment->id, 'name' => $environment->name, 'slug' => $environment->slug],
            'project' => $project === null ? null : $this->project($project),
            'website' => $this->website($website),
        ]);
    }

    /**
     * Describe a project.
     *
     * @param  Project  $project
     * @return array{id: string, name: string, slug: string}
     */
    private function project(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug];
    }

    /**
     * Describe a website.
     *
     * @param  Website  $website
     * @return array{id: int, name: string, url: string, server_id: int|null}
     */
    private function website(Website $website): array
    {
        return ['id' => $website->id, 'name' => $website->name, 'url' => $website->url, 'server_id' => $website->server_id];
    }

    /**
     * Describe an incident.
     *
     * @param  Incident  $incident
     * @return array<string, mixed>
     */
    private function incident(Incident $incident): array
    {
        return [
            'id' => $incident->id, 'title' => $incident->title, 'status' => $incident->status, 'project_id' => $incident->project_id,
            'monitor_id' => $incident->monitor_id, 'opened_at' => $incident->opened_at->utc()->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->utc()->toIso8601String(), 'closure_reason' => $incident->closure_reason,
        ];
    }

    /**
     * Describe a server.
     *
     * @param  Server  $server
     * @return array<string, mixed>
     */
    private function server(Server $server): array
    {
        return [
            'id' => $server->id, 'name' => $server->name, 'type' => $server->type->value, 'status' => $server->provisioning_status,
            'public_ip' => $server->public_ip, 'region' => $server->region, 'size' => $server->size,
        ];
    }
}
