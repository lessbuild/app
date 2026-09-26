<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\BackupRestore;
use App\Modules\Deployer\Models\BackupRestoreVerification;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Models\WebsiteBackup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Read authorized Deployer backup and recovery task state for Core activity. */
final class DeployerBackupActivity
{
    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedEnvironments(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        if (! Schema::connection('deployer')->hasTable('website_backups')) {
            return collect();
        }

        $websiteMappings = $this->mappedWebsites($mappedEnvironments);

        if ($websiteMappings === []) {
            return collect();
        }

        $websiteIds = array_keys($websiteMappings);
        $cutoff = CarbonImmutable::now('UTC')->subDays(30);
        $queryLimit = max(1, min(100, $limit));
        $runs = WebsiteBackup::query()
            ->whereIn('website_id', $websiteIds)
            ->where(fn (Builder $query) => $this->constrainRecentOrActionable($query, $cutoff))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($queryLimit)
            ->get(['id', 'website_id', 'status', 'started_at', 'completed_at', 'created_at', 'updated_at'])
            ->map(fn (WebsiteBackup $backup): ?ProjectWorkflowRun => $this->run(
                recordKey: 'deployer:website-backup:'.$backup->getKey(),
                type: 'backup',
                title: __('Website backup'),
                status: (string) $backup->status,
                websiteId: (string) $backup->website_id,
                startedAt: $backup->started_at,
                completedAt: $backup->completed_at,
                createdAt: $backup->created_at,
                updatedAt: $backup->updated_at,
                workspace: $workspace,
                mappings: $websiteMappings,
            ))
            ->filter();

        if (Schema::connection('deployer')->hasTable('backup_restores')) {
            $restores = BackupRestore::query()
                ->whereHas('backup', fn ($query) => $query->whereIn('website_id', $websiteIds))
                ->where(fn (Builder $query) => $this->constrainRecentOrActionable($query, $cutoff))
                ->with('backup:id,website_id')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit($queryLimit)
                ->get(['id', 'website_backup_id', 'status', 'started_at', 'completed_at', 'created_at', 'updated_at'])
                ->map(fn (BackupRestore $restore): ?ProjectWorkflowRun => $this->run(
                    recordKey: 'deployer:backup-restore:'.$restore->getKey(),
                    type: 'restore',
                    title: __('Backup restore'),
                    status: (string) $restore->status,
                    websiteId: (string) $restore->backup?->website_id,
                    startedAt: $restore->started_at,
                    completedAt: $restore->completed_at,
                    createdAt: $restore->created_at,
                    updatedAt: $restore->updated_at,
                    workspace: $workspace,
                    mappings: $websiteMappings,
                ))
                ->filter();

            $runs = $runs->concat($restores);
        }

        if (Schema::connection('deployer')->hasTable('backup_restore_verifications')) {
            $verifications = BackupRestoreVerification::query()
                ->whereHas('backup', fn ($query) => $query->whereIn('website_id', $websiteIds))
                ->where(fn (Builder $query) => $this->constrainRecentOrActionable($query, $cutoff))
                ->with('backup:id,website_id')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit($queryLimit)
                ->get(['id', 'website_backup_id', 'status', 'started_at', 'completed_at', 'created_at', 'updated_at'])
                ->map(fn (BackupRestoreVerification $verification): ?ProjectWorkflowRun => $this->run(
                    recordKey: 'deployer:backup-verification:'.$verification->getKey(),
                    type: 'verification',
                    title: __('Backup verification'),
                    status: (string) $verification->status,
                    websiteId: (string) $verification->backup?->website_id,
                    startedAt: $verification->started_at,
                    completedAt: $verification->completed_at,
                    createdAt: $verification->created_at,
                    updatedAt: $verification->updated_at,
                    workspace: $workspace,
                    mappings: $websiteMappings,
                ))
                ->filter();

            $runs = $runs->concat($verifications);
        }

        return $runs->sortByDesc(fn (ProjectWorkflowRun $run): int => $run->recordedAt->getTimestamp())
            ->take($queryLimit)
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>
     */
    private function mappedWebsites(array $mappedEnvironments): array
    {
        $websiteIds = collect($mappedEnvironments)
            ->map(fn (array $mapping): ?string => $mapping['environment']->website_id === null ? null : (string) $mapping['environment']->website_id)
            ->filter()
            ->unique()
            ->values();

        if ($websiteIds->isEmpty()) {
            return [];
        }

        $websites = Website::query()
            ->whereIn('id', $websiteIds)
            ->with('server:id,organization_id')
            ->get(['id', 'organization_id', 'server_id'])
            ->keyBy(fn (Website $website): string => (string) $website->getKey());
        $mapped = [];

        foreach ($mappedEnvironments as $mapping) {
            $environment = $mapping['environment'];
            if ($environment->website_id === null) {
                continue;
            }

            $website = $websites->get((string) $environment->website_id);
            $server = $website?->server;
            $matchesEnvironmentServer = $environment->server_id === null
                || ($website !== null && (string) $website->server_id === (string) $environment->server_id);

            if (! $website instanceof Website
                || (int) $website->organization_id !== $mapping['organization_id']
                || ! $server instanceof Server
                || (int) $server->organization_id !== $mapping['organization_id']
                || ! $matchesEnvironmentServer) {
                continue;
            }

            $mapped[(string) $website->getKey()] ??= $mapping;
        }

        return $mapped;
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappings
     */
    private function run(
        string $recordKey,
        string $type,
        string $title,
        string $status,
        string $websiteId,
        mixed $startedAt,
        mixed $completedAt,
        mixed $createdAt,
        mixed $updatedAt,
        Workspace $workspace,
        array $mappings,
    ): ?ProjectWorkflowRun {
        $mapping = $mappings[$websiteId] ?? null;
        if ($mapping === null) {
            return null;
        }

        $state = match ($status) {
            'queued' => ProjectWorkflowStepState::Pending,
            'running' => ProjectWorkflowStepState::Processing,
            'succeeded' => ProjectWorkflowStepState::Succeeded,
            'failed' => ProjectWorkflowStepState::Failed,
            default => ProjectWorkflowStepState::Unknown,
        };
        $recordedAt = $createdAt?->toImmutable()->utc()
            ?? $updatedAt?->toImmutable()->utc()
            ?? CarbonImmutable::now('UTC');
        $attemptedAt = $startedAt?->toImmutable()->utc();
        $resolvedAt = $state === ProjectWorkflowStepState::Succeeded
            ? $completedAt?->toImmutable()->utc()
            : null;
        $resultUrl = Route::has('backups.index')
            ? route('backups.index', ['organization_id' => $mapping['organization_id']]).'#backup-history-list'
            : null;
        $stepTitle = match ($type) {
            'backup' => __('Website backup'),
            'restore' => __('Backup restore'),
            default => __('Independent restore verification'),
        };

        return new ProjectWorkflowRun(
            key: $recordKey,
            title: $title,
            recordedAt: $recordedAt,
            projectId: (string) $mapping['project']->getKey(),
            projectName: $mapping['project']->name,
            projectUrl: route('core.projects.show', [$workspace, $mapping['project']]),
            steps: [new ProjectWorkflowStep(
                product: 'deployer',
                productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                title: __(':environment · :task', ['environment' => $mapping['label'], 'task' => $stepTitle]),
                detail: $this->detail($state),
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $attemptedAt,
                completedAt: $resolvedAt,
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'] ?? null,
                environmentName: $mapping['canonical_environment_name'] ?? $mapping['label'],
            )],
        );
    }

    private function detail(ProjectWorkflowStepState $state): string
    {
        return match ($state) {
            ProjectWorkflowStepState::Pending => __('This Deployer task is queued.'),
            ProjectWorkflowStepState::Processing => __('This Deployer task is running.'),
            ProjectWorkflowStepState::Succeeded => __('This Deployer task completed successfully.'),
            ProjectWorkflowStepState::Failed => __('This Deployer task failed. Open backup history for authorized details.'),
            default => __('The Deployer task state is unavailable. Open backup history for current information.'),
        };
    }

    /**
     * Add bounded current and recent terminal work while excluding old successful history.
     */
    private function constrainRecentOrActionable(Builder $query, CarbonImmutable $cutoff): void
    {
        $query->whereIn('status', ['queued', 'running'])
            ->orWhere('updated_at', '>=', $cutoff);
    }
}
