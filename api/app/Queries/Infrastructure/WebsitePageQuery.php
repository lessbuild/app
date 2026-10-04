<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Data\Infrastructure\BackupSummary;
use App\Data\Infrastructure\WebsiteFormOptions;
use App\Enums\EnvironmentKind;
use App\Enums\ProviderType;
use App\Models\BackupDestination;
use App\Models\DatabaseClone;
use App\Models\DatabaseUser;
use App\Models\Project;
use App\Models\Provider;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Models\WebsiteBackupSchedule;
use App\Models\WebsiteDomain;
use App\Services\Infrastructure\WebsiteCaddyConfiguration;
use App\Services\Infrastructure\WebsiteProvisioner;
use App\Support\Infrastructure\DatabaseTuning;

final readonly class WebsitePageQuery
{
    /**
     * Create a new WebsitePageQuery instance.
     *
     * @param  WebsitesQuery  $websites
     * @param  BackupsQuery  $backups
     * @param  WebsiteCaddyConfiguration  $caddy
     */
    public function __construct(private WebsitesQuery $websites, private BackupsQuery $backups, private WebsiteCaddyConfiguration $caddy) {}

    /**
     * Describe a website for the person looking at it: setup, health, domains, its database (inspection, users,
     * copies), backups and schedules, and its settings. What they may change depends on their role and the plan.
     *
     * @param  Project  $project
     * @param  Website  $website
     * @param  User  $viewer
     * @return array<string, mixed>
     */
    public function handle(Project $project, Website $website, User $viewer): array
    {
        $website->loadMissing(['server', 'environment.project', 'healthMonitor', 'domains.dnsProvider']);
        $canManage = $viewer->can('update', $website);
        $snapshot = $website->databaseSnapshots()->latest('id')->first();
        $environment = $website->environment;
        $health = $website->healthMonitor;

        return [
            'website' => [
                'id' => $website->id,
                'name' => $website->name,
                'url' => $website->url,
                'description' => $website->description,
                'serverId' => $website->server_id,
                'server' => $website->server?->label(),
                'status' => $website->provisioning_status,
                'provisioning' => $website->isProvisioning(),
                'stage' => $website->setup_stage,
                'finalStage' => WebsiteProvisioner::finalStage(),
                'error' => $website->provisioning_error,
                'cleanupError' => $website->placement_cleanup_error,
                'directory' => '/var/www/'.$website->deployment_slug,
                'database' => $website->databaseIdentifier(),
                'releaseRetention' => $website->release_retention,
                'environmentId' => $website->environment_id,
                'healthCheckEnabled' => (bool) $website->health_check_enabled,
                'healthCheckPath' => $website->health_check_path,
                'healthCheckIntervalMinutes' => $website->health_check_interval_minutes,
                'healthFailureThreshold' => $website->health_failure_threshold,
                'selfHealing' => (bool) $website->self_healing,
                'envFile' => $canManage ? $website->env_file : null,
                'phpVersion' => $website->phpVersion(),
                'reverb' => str_contains((string) $website->caddy_directives, '# Laravel Reverb'),
                'caddyDirectives' => $website->caddy_directives,
                'caddyError' => $website->caddy_error,
            ],
            'log' => $website->logs()->where('type', 'provisioning')->value('log'),
            'health' => [
                'monitor' => $health !== null && $environment !== null ? ['id' => $health->id, 'projectId' => $environment->project_id, 'label' => __($health->healthLabel()), 'state' => $health->healthLabel()] : null,
                'environment' => $environment === null ? null : ['project' => $environment->project->name],
            ],
            'domains' => $website->domains->sortBy(fn (WebsiteDomain $domain): array => [$domain->type === 'primary' ? 0 : 1, $domain->hostname])->map(fn (WebsiteDomain $domain): array => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'type' => $domain->type,
                'redirectUrl' => $domain->redirect_url,
                'temporary' => (bool) $domain->is_temporary,
                'dnsStatus' => $domain->dns_status,
                'sslStatus' => $domain->ssl_status,
                'certificateExpiresAt' => $domain->certificate_expires_at?->toIso8601String(),
                'dnsProvider' => $domain->dnsProvider?->name,
                'canSync' => $domain->dnsProvider !== null,
                'edge' => $domain->dnsProvider?->type === ProviderType::Cloudflare && $domain->dns_record_id !== null ? [
                    'proxied' => (bool) $domain->cdn_proxied,
                    'blockedCountries' => implode(', ', $domain->blocked_countries ?? []),
                    'blockedIps' => implode("\n", $domain->blocked_ips ?? []),
                    'rateLimit' => $domain->rate_limit_requests,
                ] : null,
                'edgeError' => $domain->edge_error,
            ])->values(),
            'dnsProviders' => Provider::query()->where('account_id', $project->account_id)->whereIn('type', array_filter(ProviderType::cases(), fn (ProviderType $type): bool => $type->managesDns()))->orderBy('name')->get()
                ->map(fn (Provider $provider): array => ['value' => (string) $provider->id, 'label' => $provider->name])->values(),
            'temporaryDomains' => filled(config('infrastructure.temporary_base_domain')),
            'inspection' => $snapshot === null ? null : [
                'status' => $snapshot->status,
                'error' => $snapshot->error,
                'sizeBytes' => $snapshot->size_bytes,
                'tables' => $snapshot->tables ?? [],
                'connections' => $snapshot->active_connections,
                'collectedAt' => $snapshot->collected_at?->toIso8601String(),
                'tuning' => $snapshot->status === 'ready' ? DatabaseTuning::suggestions($snapshot->server_status ?? [], $snapshot->slow_log_enabled) : [],
                'slowLogEnabled' => $snapshot->slow_log_enabled === true,
                'slowQueries' => $snapshot->slow_queries ?? [],
            ],
            'databaseUsers' => $website->databaseUsers()->orderBy('username')->get()->map(fn (DatabaseUser $user): array => [
                'id' => $user->id, 'username' => $user->username, 'privilege' => __(DatabaseUser::PRIVILEGES[$user->privilege] ?? $user->privilege),
                'expiresAt' => $user->expires_at?->toIso8601String(), 'status' => $user->status, 'error' => $user->error,
            ])->values(),
            'privileges' => array_map(fn (string $label): string => __($label), DatabaseUser::PRIVILEGES),
            'copyTargets' => Website::query()->where('account_id', $website->account_id)->where('server_id', $website->server_id)->whereKeyNot($website->id)->with('environment')->orderBy('name')->get()
                ->map(fn (Website $target): array => ['value' => (string) $target->id, 'label' => $target->name, 'production' => $target->environment?->kind === EnvironmentKind::Production])->values(),
            'copies' => DatabaseClone::query()->with(['source', 'target'])->where(fn ($query) => $query->where('source_website_id', $website->id)->orWhere('target_website_id', $website->id))->latest('id')->limit(5)->get()
                ->map(fn (DatabaseClone $copy): array => ['id' => $copy->id, 'source' => $copy->source->name, 'target' => $copy->target->name, 'status' => $copy->status, 'error' => $copy->error, 'createdAt' => $copy->created_at?->toIso8601String()])->values(),
            'backups' => $this->backups->recent($website->account_id, $website, 20)->map(fn (WebsiteBackup $backup): BackupSummary => BackupSummary::from($backup))->values(),
            'schedules' => $website->backupSchedules()->with(['destination', 'secondaryDestination'])->get()->map(fn (WebsiteBackupSchedule $schedule): array => [
                'id' => $schedule->id, 'frequency' => $schedule->frequency, 'weekday' => (int) $schedule->weekday, 'time' => $schedule->run_at, 'destination' => $schedule->destination->name,
                'secondaryDestination' => $schedule->secondaryDestination?->name, 'retention' => $schedule->retention_count, 'monthlyDrill' => (bool) $schedule->monthly_drill,
            ])->values(),
            'backupDestinations' => BackupDestination::query()->where('account_id', $website->account_id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (BackupDestination $destination): array => ['value' => (string) $destination->id, 'label' => $destination->name])->values(),
            'phpVersions' => Website::PHP_VERSIONS,
            'defaultPhpVersion' => (string) config('infrastructure.default_php_version'),
            'caddyConfiguration' => $canManage ? $this->caddy->php($website, $website->deploymentPath('current').'/public') : null,
            'options' => $canManage ? WebsiteFormOptions::for($this->websites, $project->account) : null,
            'canManage' => $canManage,
            'canBackUp' => $viewer->can('backUp', $website),
            'canManageDatabase' => $viewer->can('manageDatabase', $website),
            'canBrowseFiles' => $website->server !== null && $viewer->can('runCommands', $website->server),
        ];
    }
}
