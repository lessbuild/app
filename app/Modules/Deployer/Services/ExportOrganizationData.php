<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Models\BackupDestination;
use App\Modules\Deployer\Models\BackupRestore;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\DeploymentSchedule;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\EnvironmentProcess;
use App\Modules\Deployer\Models\EnvironmentResource;
use App\Modules\Deployer\Models\EnvironmentVariable;
use App\Modules\Deployer\Models\MetricAlertRule;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\OrganizationInvitation;
use App\Modules\Deployer\Models\PreviewDeployment;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\RepositoryWebhookDelivery;
use App\Modules\Deployer\Models\ScalingSchedule;
use App\Modules\Deployer\Models\ScheduledTask;
use App\Modules\Deployer\Models\ScheduledTaskRun;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\StatusIncident;
use App\Modules\Deployer\Models\StatusPage;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Models\WebsiteBackup;
use App\Modules\Deployer\Models\WebsiteBackupSchedule;
use App\Modules\Deployer\Models\WebsiteDomain;
use App\Modules\Deployer\Models\WebsiteHealthCheck;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ExportOrganizationData
{
    /**
     * @param  resource  $output
     */
    public function write(Organization $organization, mixed $output): void
    {
        foreach ($this->records($organization) as $record) {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            $written = 0;
            $length = strlen($line);

            while ($written < $length) {
                $bytes = fwrite($output, substr($line, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('The Deployer workspace export stream could not be written.');
                }

                $written += $bytes;
            }
        }
    }

    /**
     * @return Generator<int, array{type: string, data: array<string, mixed>}>
     */
    private function records(Organization $organization): Generator
    {
        yield $this->record('export', [
            'format' => 'buildpusher-deployer-workspace-export',
            'version' => 1,
            'exported_at' => now('UTC')->toIso8601String(),
            'record_types' => [
                'workspace',
                'member',
                'invitation',
                'project',
                'environment',
                'environment_variable_metadata',
                'environment_process',
                'environment_resource_metadata',
                'provider_metadata',
                'server_metadata',
                'website',
                'website_domain',
                'website_health_check',
                'repository',
                'build',
                'repository_webhook_delivery',
                'preview_deployment',
                'deployment_schedule',
                'scaling_schedule',
                'scheduled_task_metadata',
                'scheduled_task_run_metadata',
                'status_page',
                'status_page_website',
                'status_incident',
                'backup_destination_metadata',
                'website_backup_schedule',
                'website_backup_metadata',
                'backup_restore_metadata',
                'metric_alert_rule',
                'alert_destination_metadata',
            ],
            'privacy' => [
                'workspace_secrets_and_access_tokens_are_excluded' => true,
                'environment_variable_values_and_process_or_task_commands_are_excluded' => true,
                'deployment_payloads_and_log_or_command_output_are_excluded' => true,
                'backup_file_contents_and_provider_credentials_are_excluded' => true,
                'alert_endpoints_and_signing_secrets_are_excluded' => true,
                'invitation_token_hashes_and_status_subscriber_records_are_excluded' => true,
                'workflow_and_secret_bearing_configuration_bodies_are_excluded' => true,
            ],
        ]);

        yield $this->record('workspace', [
            'id' => $organization->getKey(),
            'name' => $organization->name,
            'slug' => $organization->slug,
            'owner_id' => $organization->owner_id,
            'created_at' => $organization->created_at?->toIso8601String(),
            'updated_at' => $organization->updated_at?->toIso8601String(),
        ]);

        $owner = $organization->owner()
            ->select(['users.id', 'users.name', 'users.email'])
            ->first();

        if ($owner !== null) {
            yield $this->record('member', [
                'id' => $owner->getKey(),
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => 'owner',
            ]);
        }

        foreach ($organization->members()
            ->select(['users.id', 'users.name', 'users.email'])
            ->where('users.id', '!=', $organization->owner_id)
            ->orderBy('users.id')
            ->cursor() as $member) {
            yield $this->record('member', [
                'id' => $member->getKey(),
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
                'joined_at' => $member->pivot->created_at?->toIso8601String(),
                'updated_at' => $member->pivot->updated_at?->toIso8601String(),
            ]);
        }

        yield from $this->cursorRecords('invitation', OrganizationInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->select(['id', 'organization_id', 'invited_by', 'email', 'role', 'expires_at', 'accepted_at', 'created_at', 'updated_at']), [
                'id', 'organization_id', 'invited_by', 'email', 'role', 'expires_at', 'accepted_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('project', Project::query()
            ->where('organization_id', $organization->getKey())
            ->select(['id', 'organization_id', 'created_by', 'name', 'slug', 'description', 'preset', 'preview_enabled', 'preview_domain', 'preview_ttl_hours', 'created_at', 'updated_at']), [
                'id', 'organization_id', 'created_by', 'name', 'slug', 'description', 'preset', 'preview_enabled', 'preview_domain', 'preview_ttl_hours', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('environment', Environment::query()
            ->whereIn('project_id', $this->projectIds($organization))
            ->select(['id', 'project_id', 'server_id', 'website_id', 'name', 'slug', 'type', 'branch', 'is_protected', 'requires_deployment_approval', 'minimum_replicas', 'maximum_replicas', 'hibernate_after_minutes', 'status', 'created_at', 'updated_at']), [
                'id', 'project_id', 'server_id', 'website_id', 'name', 'slug', 'type', 'branch', 'is_protected', 'requires_deployment_approval', 'minimum_replicas', 'maximum_replicas', 'hibernate_after_minutes', 'status', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('environment_variable_metadata', EnvironmentVariable::query()
            ->whereIn('environment_id', $this->environmentIds($organization))
            ->select(['id', 'environment_id', 'updated_by', 'key', 'is_secret', 'scope', 'current_version', 'rotated_at', 'rotation_due_at', 'created_at', 'updated_at']), [
                'id', 'environment_id', 'updated_by', 'key', 'is_secret', 'scope', 'current_version', 'rotated_at', 'rotation_due_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('environment_process', EnvironmentProcess::query()
            ->whereIn('environment_id', $this->environmentIds($organization))
            ->select(['id', 'environment_id', 'name', 'type', 'replicas', 'restart_policy', 'restart_delay_seconds', 'is_enabled', 'created_at', 'updated_at']), [
                'id', 'environment_id', 'name', 'type', 'replicas', 'restart_policy', 'restart_delay_seconds', 'is_enabled', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('environment_resource_metadata', EnvironmentResource::query()
            ->whereIn('environment_id', $this->environmentIds($organization))
            ->select(['id', 'environment_id', 'name', 'type', 'is_managed', 'status', 'created_at', 'updated_at']), [
                'id', 'environment_id', 'name', 'type', 'is_managed', 'status', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('provider_metadata', Provider::query()
            ->where('organization_id', $organization->getKey())
            ->select(['id', 'organization_id', 'user_id', 'provider', 'name', 'description', 'connection_status', 'connection_checked_at', 'created_at', 'updated_at']), [
                'id', 'organization_id', 'user_id', 'provider', 'name', 'description', 'connection_status', 'connection_checked_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('server_metadata', Server::query()
            ->where('organization_id', $organization->getKey())
            ->select(['id', 'organization_id', 'user_id', 'provider_id', 'identifier', 'name', 'display_name', 'type', 'region', 'image', 'size', 'ssh_port', 'public_ip', 'private_ip', 'provisioning_status', 'created_at', 'updated_at']), [
                'id', 'organization_id', 'user_id', 'provider_id', 'identifier', 'name', 'display_name', 'type', 'region', 'image', 'size', 'ssh_port', 'public_ip', 'private_ip', 'provisioning_status', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('website', Website::withTrashed()
            ->where('organization_id', $organization->getKey())
            ->select(['id', 'organization_id', 'user_id', 'server_id', 'name', 'description', 'url', 'setup_stage', 'provisioning_status', 'created_at', 'updated_at', 'deleted_at']), [
                'id', 'organization_id', 'user_id', 'server_id', 'name', 'description', 'url', 'setup_stage', 'provisioning_status', 'created_at', 'updated_at', 'deleted_at',
            ]);

        yield from $this->cursorRecords('website_domain', WebsiteDomain::query()
            ->whereIn('website_id', $this->websiteIds($organization))
            ->select(['id', 'website_id', 'created_by', 'hostname', 'type', 'redirect_url', 'is_temporary', 'dns_status', 'ssl_status', 'certificate_expires_at', 'last_checked_at', 'created_at', 'updated_at']), [
                'id', 'website_id', 'created_by', 'hostname', 'type', 'redirect_url', 'is_temporary', 'dns_status', 'ssl_status', 'certificate_expires_at', 'last_checked_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('website_health_check', WebsiteHealthCheck::query()
            ->whereIn('website_id', $this->websiteIds($organization))
            ->select(['id', 'website_id', 'successful', 'source', 'http_status', 'duration_ms', 'checked_at']), [
                'id', 'website_id', 'successful', 'source', 'http_status', 'duration_ms', 'checked_at',
            ]);

        yield from $this->cursorRecords('repository', Repository::withTrashed()
            ->where('organization_id', $organization->getKey())
            ->select(['id', 'organization_id', 'user_id', 'provider_id', 'website_id', 'setup_stage', 'name', 'url', 'description', 'branch', 'auto_deploy_include_paths', 'auto_deploy_exclude_paths', 'webhook_enabled', 'webhook_pending', 'webhook_last_received_at', 'deployment_root', 'created_at', 'updated_at', 'deleted_at']), [
                'id', 'organization_id', 'user_id', 'provider_id', 'website_id', 'setup_stage', 'name', 'url', 'description', 'branch', 'auto_deploy_include_paths', 'auto_deploy_exclude_paths', 'webhook_enabled', 'webhook_pending', 'webhook_last_received_at', 'deployment_root', 'created_at', 'updated_at', 'deleted_at',
            ]);

        yield from $this->cursorRecords('build', Build::query()
            ->whereIn('repository_id', $this->repositoryIds($organization))
            ->select(['id', 'repository_id', 'environment_id', 'status', 'revision', 'built_at', 'started_at', 'finished_at', 'created_at', 'updated_at']), [
                'id', 'repository_id', 'environment_id', 'status', 'revision', 'built_at', 'started_at', 'finished_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('repository_webhook_delivery', RepositoryWebhookDelivery::query()
            ->whereIn('repository_id', $this->repositoryIds($organization))
            ->select(['id', 'repository_id', 'delivery_id', 'status', 'created_at', 'updated_at']), [
                'id', 'repository_id', 'delivery_id', 'status', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('preview_deployment', PreviewDeployment::query()
            ->whereIn('project_id', $this->projectIds($organization))
            ->select(['id', 'project_id', 'source_repository_id', 'environment_id', 'website_id', 'repository_id', 'pull_request_number', 'title', 'source_branch', 'revision', 'status', 'url', 'last_activity_at', 'closed_at', 'created_at', 'updated_at']), [
                'id', 'project_id', 'source_repository_id', 'environment_id', 'website_id', 'repository_id', 'pull_request_number', 'title', 'source_branch', 'revision', 'status', 'url', 'last_activity_at', 'closed_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('deployment_schedule', DeploymentSchedule::query()
            ->whereIn('environment_id', $this->environmentIds($organization))
            ->select(['id', 'environment_id', 'created_by', 'name', 'cron_expression', 'timezone', 'is_enabled', 'last_run_at', 'created_at', 'updated_at']), [
                'id', 'environment_id', 'created_by', 'name', 'cron_expression', 'timezone', 'is_enabled', 'last_run_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('scaling_schedule', ScalingSchedule::query()
            ->whereIn('environment_id', $this->environmentIds($organization))
            ->select(['id', 'environment_id', 'created_by', 'name', 'replicas', 'cron_expression', 'timezone', 'is_enabled', 'last_run_at', 'created_at', 'updated_at']), [
                'id', 'environment_id', 'created_by', 'name', 'replicas', 'cron_expression', 'timezone', 'is_enabled', 'last_run_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('scheduled_task_metadata', ScheduledTask::query()
            ->whereIn('environment_id', $this->environmentIds($organization))
            ->select(['id', 'environment_id', 'created_by', 'name', 'cron_expression', 'timezone', 'timeout_seconds', 'without_overlapping', 'alert_on_failure', 'is_enabled', 'last_queued_at', 'last_finished_at', 'last_status', 'created_at', 'updated_at']), [
                'id', 'environment_id', 'created_by', 'name', 'cron_expression', 'timezone', 'timeout_seconds', 'without_overlapping', 'alert_on_failure', 'is_enabled', 'last_queued_at', 'last_finished_at', 'last_status', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('scheduled_task_run_metadata', ScheduledTaskRun::query()
            ->whereIn('scheduled_task_id', ScheduledTask::query()
                ->whereIn('environment_id', $this->environmentIds($organization))
                ->select('id'))
            ->select(['id', 'scheduled_task_id', 'status', 'started_at', 'finished_at', 'duration_ms', 'created_at', 'updated_at']), [
                'id', 'scheduled_task_id', 'status', 'started_at', 'finished_at', 'duration_ms', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('status_page', StatusPage::query()
            ->where('organization_id', $organization->getKey())
            ->select(['id', 'organization_id', 'created_by', 'name', 'slug', 'description', 'is_published', 'created_at', 'updated_at']), [
                'id', 'organization_id', 'created_by', 'name', 'slug', 'description', 'is_published', 'created_at', 'updated_at',
            ]);

        foreach (DB::connection('deployer')->table('status_page_website')
            ->whereIn('status_page_id', $this->statusPageIds($organization))
            ->select(['status_page_id', 'website_id', 'display_name', 'position'])
            ->orderBy('status_page_id')
            ->orderBy('website_id')
            ->cursor() as $relation) {
            yield $this->record('status_page_website', [
                'status_page_id' => $relation->status_page_id,
                'website_id' => $relation->website_id,
                'display_name' => $relation->display_name,
                'position' => $relation->position,
            ]);
        }

        yield from $this->cursorRecords('status_incident', StatusIncident::query()
            ->whereIn('status_page_id', $this->statusPageIds($organization))
            ->select(['id', 'status_page_id', 'created_by', 'kind', 'status', 'severity', 'title', 'message', 'starts_at', 'ends_at', 'resolved_at', 'created_at', 'updated_at']), [
                'id', 'status_page_id', 'created_by', 'kind', 'status', 'severity', 'title', 'message', 'starts_at', 'ends_at', 'resolved_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('backup_destination_metadata', BackupDestination::query()
            ->where('organization_id', $organization->getKey())
            ->select(['id', 'organization_id', 'created_by', 'name', 'bucket', 'region', 'path_prefix', 'is_active', 'last_verified_at', 'created_at', 'updated_at']), [
                'id', 'organization_id', 'created_by', 'name', 'bucket', 'region', 'path_prefix', 'is_active', 'last_verified_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('website_backup_schedule', WebsiteBackupSchedule::query()
            ->whereIn('website_id', $this->websiteIds($organization))
            ->select(['id', 'website_id', 'backup_destination_id', 'frequency', 'weekday', 'run_at', 'retention_count', 'is_active', 'last_queued_at', 'created_at', 'updated_at']), [
                'id', 'website_id', 'backup_destination_id', 'frequency', 'weekday', 'run_at', 'retention_count', 'is_active', 'last_queued_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('website_backup_metadata', WebsiteBackup::query()
            ->whereIn('website_id', $this->websiteIds($organization))
            ->select(['id', 'website_id', 'backup_destination_id', 'website_backup_schedule_id', 'triggered_by', 'status', 'size_bytes', 'started_at', 'completed_at', 'created_at', 'updated_at']), [
                'id', 'website_id', 'backup_destination_id', 'website_backup_schedule_id', 'triggered_by', 'status', 'size_bytes', 'started_at', 'completed_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('backup_restore_metadata', BackupRestore::query()
            ->whereIn('website_backup_id', WebsiteBackup::query()
                ->whereIn('website_id', $this->websiteIds($organization))
                ->select('id'))
            ->select(['id', 'website_backup_id', 'requested_by', 'status', 'started_at', 'completed_at', 'created_at', 'updated_at']), [
                'id', 'website_backup_id', 'requested_by', 'status', 'started_at', 'completed_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('metric_alert_rule', MetricAlertRule::query()
            ->where('organization_id', $organization->getKey())
            ->select(['id', 'organization_id', 'created_by', 'server_id', 'name', 'metric', 'operator', 'threshold', 'consecutive_breaches', 'cooldown_minutes', 'is_enabled', 'last_evaluated_at', 'last_triggered_at', 'created_at', 'updated_at']), [
                'id', 'organization_id', 'created_by', 'server_id', 'name', 'metric', 'operator', 'threshold', 'consecutive_breaches', 'cooldown_minutes', 'is_enabled', 'last_evaluated_at', 'last_triggered_at', 'created_at', 'updated_at',
            ]);

        yield from $this->cursorRecords('alert_destination_metadata', AlertDestination::query()
            ->where('organization_id', $organization->getKey())
            ->select(['id', 'organization_id', 'created_by', 'name', 'type', 'events', 'is_active', 'last_delivered_at', 'last_failed_at', 'created_at', 'updated_at']), [
                'id', 'organization_id', 'created_by', 'name', 'type', 'events', 'is_active', 'last_delivered_at', 'last_failed_at', 'created_at', 'updated_at',
            ]);
    }

    /** @param array<int, string> $fields
     * @return Generator<int, array{type: string, data: array<string, mixed>}>
     */
    private function cursorRecords(string $type, Builder $query, array $fields): Generator
    {
        foreach ($query->orderBy('id')->cursor() as $model) {
            yield $this->modelRecord($type, $model, $fields);
        }
    }

    /** @param array<int, string> $fields
     * @return array{type: string, data: array<string, mixed>}
     */
    private function modelRecord(string $type, Model $model, array $fields): array
    {
        $data = [];

        foreach ($fields as $field) {
            $data[$field] = $model->getAttribute($field);
        }

        return $this->record($type, $data);
    }

    /** @return Builder<Project> */
    private function projectIds(Organization $organization): Builder
    {
        return Project::query()->where('organization_id', $organization->getKey())->select('id');
    }

    /** @return Builder<Environment> */
    private function environmentIds(Organization $organization): Builder
    {
        return Environment::query()->whereIn('project_id', $this->projectIds($organization))->select('id');
    }

    /** @return Builder<Website> */
    private function websiteIds(Organization $organization): Builder
    {
        return Website::withTrashed()->where('organization_id', $organization->getKey())->select('id');
    }

    /** @return Builder<Repository> */
    private function repositoryIds(Organization $organization): Builder
    {
        return Repository::withTrashed()->where('organization_id', $organization->getKey())->select('id');
    }

    /** @return Builder<StatusPage> */
    private function statusPageIds(Organization $organization): Builder
    {
        return StatusPage::query()->where('organization_id', $organization->getKey())->select('id');
    }

    /** @param array<string, mixed> $data
     * @return array{type: string, data: array<string, mixed>}
     */
    private function record(string $type, array $data): array
    {
        return ['type' => $type, 'data' => $data];
    }
}
