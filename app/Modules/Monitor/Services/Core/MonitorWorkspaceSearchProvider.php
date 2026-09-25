<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceSearchProvider;
use App\Core\Data\Search\WorkspaceSearchResult;
use App\Core\Exceptions\Search\WorkspaceSearchProviderUnavailable;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\Search\WorkspaceSearchPattern;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Workspace as MonitorWorkspace;
use Illuminate\Support\Facades\Route;

final class MonitorWorkspaceSearchProvider implements WorkspaceSearchProvider
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function search(PlatformUser $user, Workspace $workspace, string $query): array
    {
        if (! Route::has('monitor.incidents.show')) {
            throw new WorkspaceSearchProviderUnavailable('Monitor incident search is unavailable.');
        }

        $sourceUserIds = $this->identities->sourceIdsFor($user, 'monitor');
        $sourceWorkspaceIds = $this->identities->sourceIdsForCanonical(
            'monitor',
            'workspace',
            $workspace->getKey(),
            'workspace',
        );

        if ($sourceUserIds === [] || $sourceWorkspaceIds === []) {
            return [];
        }

        $workspaceIds = MonitorWorkspace::query()
            ->whereKey($sourceWorkspaceIds)
            ->whereHas('members', fn ($members) => $members->whereIn('users.id', $sourceUserIds))
            ->pluck('id');

        if ($workspaceIds->isEmpty()) {
            return [];
        }

        $pattern = WorkspaceSearchPattern::contains($query);
        $authorizedWorkspaceIds = $workspaceIds
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $incidents = Incident::query()
            ->where(function ($incidents) use ($workspaceIds): void {
                $incidents
                    ->whereHas('alertRule.environment.application', fn ($applications) => $applications->whereIn('workspace_id', $workspaceIds))
                    ->orWhereHas('monitor.environment.application', fn ($applications) => $applications->whereIn('workspace_id', $workspaceIds));
            })
            ->where(function ($incidents) use ($workspaceIds): void {
                $incidents
                    ->whereNull('alert_rule_id')
                    ->orWhereHas('alertRule.environment.application', fn ($applications) => $applications->whereIn('workspace_id', $workspaceIds));
            })
            ->where(function ($incidents) use ($workspaceIds): void {
                $incidents
                    ->whereNull('monitor_id')
                    ->orWhereHas('monitor.environment.application', fn ($applications) => $applications->whereIn('workspace_id', $workspaceIds));
            })
            ->where(function ($incidents) use ($pattern, $query): void {
                $incidents->whereRaw("status LIKE ? ESCAPE '!'", [$pattern]);

                if (ctype_digit($query)) {
                    $incidents->orWhereKey($query);
                }

                $incidents
                    ->orWhereHas('alertRule', fn ($rules) => $rules->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('monitor', fn ($monitors) => $monitors->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
            })
            ->with([
                'alertRule:id,environment_id,name',
                'alertRule.environment:id,application_id',
                'alertRule.environment.application:id,workspace_id',
                'monitor:id,environment_id,name',
                'monitor.environment:id,application_id',
                'monitor.environment.application:id,workspace_id',
            ])
            ->latest('opened_at')
            ->limit(5)
            ->get(['id', 'alert_rule_id', 'monitor_id', 'status', 'opened_at']);

        return $incidents
            ->map(function (Incident $incident) use ($authorizedWorkspaceIds): ?WorkspaceSearchResult {
                $sourceWorkspaceId = $this->sourceWorkspaceIdFor($incident, $authorizedWorkspaceIds);

                if ($sourceWorkspaceId === null) {
                    return null;
                }

                return new WorkspaceSearchResult(
                    type: __('Incident'),
                    title: __('Incident #:id', ['id' => $incident->getKey()]),
                    subtitle: $incident->statusLabel(),
                    url: route('monitor.incidents.show', [
                        'incident' => $incident->getKey(),
                        'workspace_id' => $sourceWorkspaceId,
                    ]),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @param list<string> $authorizedWorkspaceIds */
    private function sourceWorkspaceIdFor(Incident $incident, array $authorizedWorkspaceIds): ?int
    {
        $sourceWorkspaceId = $incident->monitor?->environment?->application?->workspace_id
            ?? $incident->alertRule?->environment?->application?->workspace_id;

        if ($sourceWorkspaceId === null) {
            return null;
        }

        $sourceWorkspaceId = (string) $sourceWorkspaceId;

        return in_array($sourceWorkspaceId, $authorizedWorkspaceIds, true)
            ? (int) $sourceWorkspaceId
            : null;
    }
}
