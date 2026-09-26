<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\WorkspaceActivityProvider;
use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Data\Projects\WorkspaceActivitySnapshot;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class AnalyticsWorkspaceActivityProvider implements WorkspaceActivityProvider
{
    public function __construct(
        private readonly AnalyticsProjectLink $projectLinks,
        private readonly AnalyticsWorkspaceAccess $workspaceAccess,
        private readonly WorkspaceProjectAccess $projectAccess,
    ) {}

    /**
     * @param  Collection<int, CoreProject>  $projects
     */
    public function recentForWorkspace(
        PlatformUser $user,
        CoreWorkspace $workspace,
        Collection $projects,
        int $limit,
    ): WorkspaceActivitySnapshot {
        if ($projects->isEmpty() || ! Route::has('analytics.reports.exports.record')) {
            return new WorkspaceActivitySnapshot(collect());
        }

        try {
            $mappedSites = $this->mappedSites($user, $projects);

            if ($mappedSites === []) {
                return new WorkspaceActivitySnapshot(collect());
            }

            $resultLimit = max(1, min(100, $limit));
            $siteIds = array_keys($mappedSites);
            $workspaceIds = collect($mappedSites)
                ->map(fn (array $mapping): string => (string) $mapping['site']->workspace_id)
                ->unique()
                ->all();
            $exports = ReportExport::query()
                ->whereIn('site_id', $siteIds)
                ->whereIn('workspace_id', $workspaceIds)
                ->with('site:id,workspace_id,name,slug')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($resultLimit)
                ->get([
                    'id',
                    'workspace_id',
                    'site_id',
                    'status',
                    'expires_at',
                    'completed_at',
                    'created_at',
                    'updated_at',
                ]);

            $recentCutoff = CarbonImmutable::now('UTC')->subDays(30);
            $batches = IngestionBatch::query()
                ->whereIn('site_id', $siteIds)
                ->where(function ($query) use ($recentCutoff): void {
                    $query->whereNotIn('status', ['processed', 'failed'])
                        ->orWhere('accepted_at', '>=', $recentCutoff);
                })
                ->with('site:id,workspace_id,name,slug')
                ->orderByDesc('accepted_at')
                ->orderByDesc('id')
                ->limit($resultLimit)
                ->get([
                    'id',
                    'site_id',
                    'event_count',
                    'status',
                    'accepted_at',
                    'processed_at',
                    'created_at',
                    'updated_at',
                ]);

            $runs = $exports
                ->map(fn (ReportExport $export): ?ProjectWorkflowRun => $this->runFor($export, $workspace, $mappedSites))
                ->merge($batches->map(fn (IngestionBatch $batch): ?ProjectWorkflowRun => $this->runForBatch($batch, $workspace, $mappedSites)))
                ->filter()
                ->sortByDesc(fn (ProjectWorkflowRun $run): int => $run->recordedAt->getTimestamp())
                ->take($resultLimit)
                ->values();

            return new WorkspaceActivitySnapshot($runs);
        } catch (LostConnectionException|QueryException) {
            return new WorkspaceActivitySnapshot(collect(), available: false);
        }
    }

    /**
     * @param  Collection<int, CoreProject>  $projects
     * @return array<string, array{project: CoreProject, site: Site}>
     */
    private function mappedSites(PlatformUser $user, Collection $projects): array
    {
        $mapped = [];

        foreach ($projects as $project) {
            if (! $this->projectAccess->canAccessProductResource($user, $project, 'analytics')) {
                continue;
            }

            $resourcesBySite = ProjectResource::query()
                ->where('project_id', $project->getKey())
                ->where('product', 'analytics')
                ->where('resource_type', 'site')
                ->where('status', 'active')
                ->with('environment:id,name')
                ->get(['id', 'project_id', 'resource_id', 'environment_id'])
                ->groupBy('resource_id');

            foreach ($this->projectLinks->accessibleSites($user, $project) as $site) {
                if (! $this->workspaceAccess->hasAccess($user, $site->workspace)) {
                    continue;
                }

                $resourceMappings = $resourcesBySite->get((string) $site->getKey(), collect());
                $environmentIds = $resourceMappings->pluck('environment_id')->filter()->unique()->values();
                $environment = $resourceMappings->count() === 1 && $environmentIds->count() === 1
                    ? $resourceMappings->first()->environment
                    : null;

                $mapped[(string) $site->getKey()] ??= [
                    'project' => $project,
                    'site' => $site,
                    'canonical_environment_id' => $environment?->getKey() === null ? null : (string) $environment->getKey(),
                    'canonical_environment_name' => $environment?->name,
                ];
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, array{project: CoreProject, site: Site}>  $mappedSites
     */
    private function runFor(ReportExport $export, CoreWorkspace $workspace, array $mappedSites): ?ProjectWorkflowRun
    {
        $mapping = $mappedSites[(string) $export->site_id] ?? null;
        $site = $export->site;

        if ($mapping === null
            || $site === null
            || (string) $export->workspace_id !== (string) $site->workspace_id
            || (string) $mapping['site']->getKey() !== (string) $site->getKey()) {
            return null;
        }

        $project = $mapping['project'];
        $recordedAt = $export->created_at?->toImmutable()->utc() ?? CarbonImmutable::now('UTC');
        $completedAt = $export->completed_at?->toImmutable()->utc();
        $recordUrl = Route::has('analytics.reports.exports.record')
            ? route('analytics.reports.exports.record', [$site->getKey(), $export->getKey()])
            : null;

        return new ProjectWorkflowRun(
            key: 'analytics:report-export:'.$export->getKey(),
            title: __('CSV export: :site', ['site' => $site->name]),
            recordedAt: $recordedAt,
            projectId: (string) $project->getKey(),
            projectName: $project->name,
            projectUrl: route('core.projects.show', [$workspace, $project]),
            steps: [new ProjectWorkflowStep(
                product: 'analytics',
                productLabel: (string) config('platform.products.analytics.label', __('Analytics')),
                title: __('Analytics report export'),
                detail: $this->detail($export),
                state: $this->state($export),
                recordedAt: $recordedAt,
                attemptedAt: $export->status === 'pending' ? null : $export->updated_at?->toImmutable()->utc(),
                completedAt: $completedAt,
                resultUrl: $recordUrl,
                environmentId: $mapping['canonical_environment_id'],
                environmentName: $mapping['canonical_environment_name'],
            )],
        );
    }

    /**
     * @param  array<string, array{project: CoreProject, site: Site}>  $mappedSites
     */
    private function runForBatch(IngestionBatch $batch, CoreWorkspace $workspace, array $mappedSites): ?ProjectWorkflowRun
    {
        $mapping = $mappedSites[(string) $batch->site_id] ?? null;
        $site = $batch->site;

        if ($mapping === null
            || $site === null
            || (string) $mapping['site']->getKey() !== (string) $site->getKey()
            || (string) $mapping['site']->workspace_id !== (string) $site->workspace_id) {
            return null;
        }

        $project = $mapping['project'];
        $recordedAt = $batch->accepted_at?->toImmutable()->utc() ?? CarbonImmutable::now('UTC');
        $resultUrl = Route::has('analytics.dashboard')
            ? route('analytics.dashboard', ['site' => $site->getKey()])
            : null;

        return new ProjectWorkflowRun(
            key: 'analytics:ingestion-batch:'.$batch->getKey(),
            title: __('Analytics events: :site', ['site' => $site->name]),
            recordedAt: $recordedAt,
            projectId: (string) $project->getKey(),
            projectName: $project->name,
            projectUrl: route('core.projects.show', [$workspace, $project]),
            steps: [new ProjectWorkflowStep(
                product: 'analytics',
                productLabel: (string) config('platform.products.analytics.label', __('Analytics')),
                title: __('Event processing'),
                detail: $this->batchDetail($batch),
                state: match ($batch->status) {
                    'pending' => ProjectWorkflowStepState::Pending,
                    'processing' => ProjectWorkflowStepState::Processing,
                    'processed' => ProjectWorkflowStepState::Succeeded,
                    'failed' => ProjectWorkflowStepState::Failed,
                    default => ProjectWorkflowStepState::Unknown,
                },
                recordedAt: $recordedAt,
                attemptedAt: $batch->status === 'pending' ? null : $batch->updated_at?->toImmutable()->utc(),
                completedAt: in_array($batch->status, ['processed', 'failed'], true)
                    ? $batch->processed_at?->toImmutable()->utc()
                    : null,
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'],
                environmentName: $mapping['canonical_environment_name'],
            )],
        );
    }

    private function batchDetail(IngestionBatch $batch): string
    {
        $eventCount = number_format(max(0, (int) $batch->event_count));

        return match ($batch->status) {
            'pending' => __(':count accepted events are queued for processing.', ['count' => $eventCount]),
            'processing' => __(':count accepted events are being processed.', ['count' => $eventCount]),
            'processed' => __(':count accepted events have been processed.', ['count' => $eventCount]),
            'failed' => __('This event batch failed processing. Review the Analytics site before retrying ingestion.'),
            default => __('The event-processing status is unavailable.'),
        };
    }

    private function state(ReportExport $export): ProjectWorkflowStepState
    {
        if ($export->expires_at?->isPast() && in_array($export->status, ['pending', 'processing'], true)) {
            return ProjectWorkflowStepState::Unknown;
        }

        return match ($export->status) {
            'pending' => ProjectWorkflowStepState::Pending,
            'processing' => ProjectWorkflowStepState::Processing,
            'completed' => ProjectWorkflowStepState::Succeeded,
            'failed' => ProjectWorkflowStepState::Failed,
            default => ProjectWorkflowStepState::Unknown,
        };
    }

    private function detail(ReportExport $export): string
    {
        if ($export->expires_at?->isPast() && in_array($export->status, ['pending', 'processing'], true)) {
            return __('This export has expired and is no longer being processed.');
        }

        return match ($export->status) {
            'pending' => __('The CSV export is queued for generation.'),
            'processing' => __('The CSV export is being prepared.'),
            'completed' => $export->expires_at?->isPast()
                ? __('The CSV export completed, but its download retention period has expired.')
                : __('The CSV export completed. Open its Analytics record to check download availability.'),
            'failed' => __('The CSV export failed. Open its Analytics record for safe recovery options.'),
            default => __('The export status is unavailable.'),
        };
    }
}
