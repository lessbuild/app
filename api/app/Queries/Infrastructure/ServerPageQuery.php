<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Actions\Infrastructure\ChangeRuntimeVersion;
use App\Enums\ServerType;
use App\Models\BackupDestination;
use App\Models\DatabaseBackupPlan;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerAlertRule;
use App\Models\ServerCronJob;
use App\Models\ServerDiskScan;
use App\Models\ServerFirewallRule;
use App\Models\ServerLogShipping;
use App\Models\ServerMetric;
use App\Models\ServerProcess;
use App\Models\ServerService;
use App\Models\ServerSnapshot;
use App\Models\User;
use App\Services\Infrastructure\DiskCleanup;
use App\Services\Infrastructure\ServerLogs;
use App\Services\Infrastructure\ServerProvisioningPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

final readonly class ServerPageQuery
{
    /**
     * Create a new ServerPageQuery instance.
     *
     * @param  ServerProvisioningPlan  $plan
     */
    public function __construct(private ServerProvisioningPlan $plan) {}

    /**
     * Describe a server for the person looking at it: where provisioning is, its resources over the last day, alerts,
     * diagnostics and disk clean-up, database recovery and replicas, cron jobs, processes, firewall rules, services,
     * one log, log shipping and its settings. Which of these appear depends on the server and what the person may do.
     *
     * @param  Project  $project
     * @param  Server  $server
     * @param  User  $viewer
     * @param  string  $logType  One of ServerLogs::TYPES.
     * @return array<string, mixed>
     */
    public function handle(Project $project, Server $server, User $viewer, string $logType): array
    {
        $server->loadMissing(['provider', 'replicaOf', 'diagnosticSnapshot']);
        $active = $server->provisioning_status === Server::STATUS_ACTIVE;
        $database = $server->type === ServerType::Database && $server->database_engine !== null;
        $canRunCommands = $viewer->can('runCommands', $server);
        $metrics = $server->metrics()->where('recorded_at', '>=', CarbonImmutable::now('UTC')->subDay())->orderBy('recorded_at')->get();
        $latest = $metrics->last();
        $log = $server->logSnapshots()->where('type', $logType)->first();
        $diskScan = ServerDiskScan::query()->where('server_id', $server->id)->first();
        $recoveryPlan = DatabaseBackupPlan::query()->with(['destination', 'setupExecution'])->where('server_id', $server->id)->first();
        $logShipping = ServerLogShipping::query()->with('environment.project')->where('server_id', $server->id)->first();
        $task = fn (Model $task): array => ['status' => (string) $task->getAttribute('status'), 'error' => $task->getAttribute('error')];

        return [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'label' => $server->label(),
                'displayName' => $server->display_name,
                'type' => $server->type->value,
                'typeLabel' => $server->type->label(),
                'ip' => $server->public_ip,
                'privateIp' => $server->private_ip,
                'provider' => $server->provider?->type->label(),
                'region' => $server->region,
                'status' => $server->provisioning_status,
                'provisioning' => $server->isProvisioning(),
                'stage' => $server->setup_stage,
                'finalStage' => $this->plan->finalStage($server),
                'step' => $server->provisioning_status === 'provisioning' ? $this->plan->currentStep($server) : null,
                'error' => $server->provisioning_error,
                'failurePhase' => $server->provisioning_failure_phase,
                'provisionedAt' => $server->provisioned_at?->toIso8601String(),
                'sshPort' => $server->ssh_port,
                'hostFingerprint' => $server->ssh_host_fingerprint,
                'hasHostKey' => $server->ssh_host_key !== null,
                'size' => $server->size,
                'image' => $server->image,
                'databaseEngine' => $server->database_engine,
                'nodeVersion' => $server->node_version,
                'installsNode' => in_array('node', $server->type->installs(), true),
                'snapshotBeforeChanges' => (bool) $server->snapshot_before_changes,
                'trustPrivateNetwork' => (bool) $server->trust_private_network,
            ],
            'latest' => $latest === null ? null : [
                'cpu' => $latest->cpu_percent, 'memory' => $latest->memory_percent, 'disk' => $latest->disk_percent, 'load' => $latest->load_1m,
                'recordedAt' => $latest->recorded_at->toIso8601String(), 'uptimeDays' => intdiv((int) $latest->uptime_seconds, 86400), 'processes' => $latest->process_count,
            ],
            'cpu' => $metrics->map(fn (ServerMetric $metric): array => ['at' => $metric->recorded_at->toIso8601String(), 'value' => $metric->cpu_percent])->values(),
            'alertRules' => $active ? ServerAlertRule::query()->where('account_id', $project->account_id)->where(fn ($query) => $query->whereNull('server_id')->orWhere('server_id', $server->id))->orderBy('name')->get()
                ->map(fn (ServerAlertRule $rule): array => [
                    'id' => $rule->id, 'name' => $rule->name, 'metric' => __(ServerAlertRule::METRICS[$rule->metric] ?? $rule->metric), 'operator' => $rule->operator,
                    'threshold' => (float) $rule->threshold, 'readings' => $rule->consecutive_breaches, 'everyServer' => $rule->server_id === null, 'alerting' => (bool) $rule->is_alerting,
                ])->values() : [],
            'alertMetrics' => array_map(fn (string $label): string => __($label), ServerAlertRule::METRICS),
            'diagnostics' => $server->diagnosticSnapshot === null ? null : [
                'running' => $server->diagnosticSnapshot->isRunning(),
                'finishedAt' => $server->diagnosticSnapshot->finished_at?->toIso8601String(),
                'checks' => array_map(fn (array $check): array => ['name' => __($check['name']), 'detail' => $check['detail'], 'passed' => (bool) $check['passed']], $server->diagnosticSnapshot->checks ?? []),
            ],
            'diskScan' => $diskScan === null ? null : [
                'status' => $diskScan->status,
                'error' => $diskScan->error,
                'scannedAt' => $diskScan->scanned_at?->toIso8601String(),
                'free' => $diskScan->findings['disk']['items'] ?? null,
                'size' => $diskScan->findings['disk']['bytes'] ?? null,
                'categories' => $diskScan->findings === null ? [] : array_map(fn (string $category, string $label): array => [
                    'key' => $category, 'label' => __($label), 'bytes' => (int) ($diskScan->findings[$category]['bytes'] ?? 0),
                ], array_keys(DiskCleanup::CATEGORIES), DiskCleanup::CATEGORIES),
            ],
            'recovery' => ! $active || ! $database ? null : [
                'plan' => $recoveryPlan === null ? null : [
                    'destinationId' => $recoveryPlan->backup_destination_id, 'destination' => $recoveryPlan->destination->name, 'retentionDays' => $recoveryPlan->retention_days,
                    'earliest' => $recoveryPlan->earliestRestore()->toIso8601String(), 'setupStatus' => $recoveryPlan->setupExecution?->status,
                ],
                'destinations' => BackupDestination::query()->where('account_id', $server->account_id)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (BackupDestination $destination): array => ['value' => (string) $destination->id, 'label' => $destination->name])->values(),
            ],
            'replication' => ! $active || ! $database ? null : $this->replication($server),
            'cronJobs' => ServerCronJob::query()->where('server_id', $server->id)->orderBy('id')->get()->map(fn (ServerCronJob $job): array => [
                'id' => $job->id, 'command' => $job->command, 'schedule' => $job->schedule(), 'frequency' => $job->frequency, 'user' => $job->user, ...$task($job),
            ])->values(),
            'cronPresets' => array_map(fn (string $label): string => __($label), ServerCronJob::PRESETS),
            'processes' => ServerProcess::query()->where('server_id', $server->id)->orderBy('name')->get()->map(fn (ServerProcess $process): array => [
                'id' => $process->id, 'name' => $process->name, 'command' => $process->command, 'copies' => $process->processes, 'user' => $process->user,
                'directory' => $process->directory, 'stopWaitSeconds' => $process->stop_wait_seconds, ...$task($process),
            ])->values(),
            'processPresets' => array_map(fn (array $preset): array => [...$preset, 'name' => __($preset['name'])], ServerProcess::PRESETS),
            'firewallRules' => ServerFirewallRule::query()->where('server_id', $server->id)->orderBy('name')->get()->map(fn (ServerFirewallRule $rule): array => [
                'id' => $rule->id, 'name' => $rule->name, 'port' => $rule->port, 'protocol' => $rule->protocol, 'source' => $rule->source, 'from' => $rule->from(), ...$task($rule),
            ])->values(),
            'services' => array_map(function (string $kind, array $info) use ($server, $task): array {
                $service = ServerService::query()->where('server_id', $server->id)->where('kind', $kind)->first();

                return [
                    'kind' => $kind, 'name' => $info['name'], 'description' => __($info['description']), 'secretLabel' => __($info['secret']),
                    'installed' => $service === null ? null : [
                        'id' => $service->id, ...$task($service), 'listen' => $service->listen, 'secret' => $service->status === 'active' ? $service->secret : null,
                        'address' => $service->localAddress(), 'privateAddress' => $service->listen === 'private' && $server->private_ip !== null ? str_replace('127.0.0.1', $server->private_ip, $service->localAddress()) : null,
                    ],
                ];
            }, array_keys(ServerService::KINDS), ServerService::KINDS),
            'log' => [
                'type' => $logType,
                'types' => array_keys(ServerLogs::TYPES),
                'text' => $log?->log,
                'error' => $log?->error,
                'fetching' => in_array($log?->status, ['queued', 'refreshing'], true),
                'refreshedAt' => $log?->refreshed_at?->toIso8601String(),
            ],
            'logShipping' => $logShipping === null ? null : [
                'status' => $logShipping->status, 'error' => $logShipping->last_error, 'environmentId' => $logShipping->environment_id,
                'projectId' => $logShipping->environment->project_id, 'project' => $logShipping->environment->project->name, 'environment' => $logShipping->environment->name,
            ],
            // Environments of the account's projects with Monitoring, for sending the server's logs.
            'logEnvironments' => Environment::query()->forAccount($project->account)->with('project')
                ->whereHas('project', fn ($query) => $query->whereHas('enabledServices', fn ($services) => $services->where('service', 'monitoring')))->orderBy('name')->get()
                ->map(fn (Environment $environment): array => ['value' => $environment->id, 'label' => $environment->project->name.' · '.$environment->name])->values(),
            'snapshots' => ServerSnapshot::query()->where('server_id', $server->id)->where('status', '!=', 'deleted')->latest('id')->limit(5)->get()->map(fn (ServerSnapshot $snapshot): array => [
                'id' => $snapshot->id, 'status' => $snapshot->status, 'reason' => $snapshot->reason, 'error' => $snapshot->error, 'createdAt' => $snapshot->created_at?->toIso8601String(),
            ])->values(),
            'nodeVersions' => ChangeRuntimeVersion::NODE_VERSIONS,
            'canManage' => $viewer->can('update', $server),
            'canRunCommands' => $canRunCommands,
            'canOpenTerminal' => $viewer->can('openTerminal', $server),
            'canDelete' => $viewer->can('delete', $server),
        ];
    }

    /**
     * Describe a database server's replication: the primary it copies, or its own read replicas and the servers that
     * could become one.
     *
     * @param  Server  $server
     * @return array<string, mixed>
     */
    private function replication(Server $server): array
    {
        $describe = fn (Server $replica): array => [
            'id' => $replica->id, 'name' => $replica->name, 'address' => $replica->private_ip ?? $replica->public_ip, 'status' => $replica->replication_status,
            'lagSeconds' => $replica->replication_lag_seconds, 'error' => $replica->replication_error, 'checkedAt' => $replica->replication_checked_at?->toIso8601String(),
        ];

        return [
            'primary' => $server->replicaOf === null ? null : ['name' => $server->replicaOf->name, 'self' => $describe($server)],
            'replicas' => $server->replicas()->orderBy('name')->get()->map($describe)->values(),
            'candidates' => $server->replica_of_server_id !== null ? [] : Server::query()
                ->where('account_id', $server->account_id)->whereKeyNot($server->id)->where('type', ServerType::Database->value)
                ->where('database_engine', $server->database_engine)->where('provisioning_status', Server::STATUS_ACTIVE)
                ->where(fn ($query) => $query->whereNull('replica_of_server_id')->orWhere(fn ($failed) => $failed->where('replica_of_server_id', $server->id)->where('replication_status', 'failed')))
                ->whereDoesntHave('replicas')->orderBy('name')->get()
                ->map(fn (Server $candidate): array => ['value' => (string) $candidate->id, 'label' => $candidate->name.($candidate->region ? ' · '.$candidate->region : '')])->values(),
        ];
    }
}
