<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceMonitorStatusManagementProvider;
use App\Core\Data\Status\WorkspaceMonitorStatusManagement;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ChangeStatusPage;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;

/** Reads safe public-component metadata and delegates all writes to Monitor's guarded service. */
final class MonitorWorkspaceStatusManagementProvider implements WorkspaceMonitorStatusManagementProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $workspaceAccess,
        private readonly ChangeStatusPage $changes,
    ) {}

    public function forWorkspace(PlatformUser $user, CoreWorkspace $workspace): ?WorkspaceMonitorStatusManagement
    {
        try {
            $context = $this->context($user, $workspace);
            if ($context === null) {
                return null;
            }

            [$monitorWorkspace, $productUser] = $context;
            $monitors = Monitor::query()
                ->forWorkspace($monitorWorkspace)
                ->with('environment.application')
                ->orderBy('name')
                ->orderBy('id')
                ->get();
            $pages = $monitorWorkspace->statusPages()
                ->with('components.monitor')
                ->latest('id')
                ->get();

            return new WorkspaceMonitorStatusManagement(
                monitors: $monitors->map(fn (Monitor $monitor): array => [
                    'id' => (string) $monitor->getKey(),
                    'name' => (string) $monitor->name,
                    'type' => (string) $monitor->typeLabel(),
                    'application' => (string) ($monitor->environment?->application?->name ?? __('Unavailable application')),
                    'environment' => (string) ($monitor->environment?->name ?? __('Unavailable environment')),
                    'health' => (string) $monitor->healthLabel(),
                ])->all(),
                pages: $pages->map(fn (StatusPage $page): array => [
                    'id' => (string) $page->getKey(),
                    'name' => (string) $page->name,
                    'slug' => (string) $page->slug,
                    'description' => $page->description,
                    'published' => (bool) $page->published,
                    'monitor_ids' => $page->components->map(fn ($component): string => (string) $component->monitor_id)->all(),
                    'component_names' => $page->components->map(fn ($component): string => (string) ($component->label ?: $component->monitor?->name ?? __('Archived monitor')))->all(),
                    'public_url' => route('core.status-pages.show', ['product' => 'monitor', 'slug' => $page->slug]),
                ])->all(),
                canManage: in_array($monitorWorkspace->roleFor($productUser), ['owner', 'admin'], true),
            );
        } catch (LostConnectionException|QueryException) {
            return null;
        }
    }

    public function create(PlatformUser $user, CoreWorkspace $workspace, array $attributes): bool
    {
        $context = $this->managerContext($user, $workspace);
        if ($context === null) {
            return false;
        }

        [$monitorWorkspace, $productUser] = $context;
        $this->changes->save($monitorWorkspace, $productUser, $this->normalized($attributes));

        return true;
    }

    public function update(PlatformUser $user, CoreWorkspace $workspace, string $pageId, array $attributes): bool
    {
        $context = $this->managerContext($user, $workspace);
        if ($context === null) {
            return false;
        }

        [$monitorWorkspace, $productUser] = $context;
        $page = $monitorWorkspace->statusPages()->whereKey($pageId)->first();
        if (! $page instanceof StatusPage) {
            return false;
        }

        $this->changes->save($monitorWorkspace, $productUser, $this->normalized($attributes), $page);

        return true;
    }

    public function delete(PlatformUser $user, CoreWorkspace $workspace, string $pageId): bool
    {
        $context = $this->managerContext($user, $workspace);
        if ($context === null) {
            return false;
        }

        [$monitorWorkspace, $productUser] = $context;
        $page = $monitorWorkspace->statusPages()->whereKey($pageId)->first();
        if (! $page instanceof StatusPage) {
            return false;
        }

        $this->changes->delete($monitorWorkspace, $productUser, $page);

        return true;
    }

    /** @return array{Workspace, User}|null */
    private function context(PlatformUser $user, CoreWorkspace $workspace): ?array
    {
        if (! config('platform.products.monitor.enabled', false)) {
            return null;
        }

        $membership = $this->workspaceAccess->activeMembership($user, $workspace);
        if ($membership === null || ! $this->workspaceAccess->hasProductAccess($membership, 'monitor')) {
            return null;
        }

        $workspaceIds = LegacyIdentityMap::query()
            ->where('source_product', 'monitor')
            ->where('source_entity', 'workspace')
            ->where('canonical_entity', 'workspace')
            ->where('canonical_id', (string) $workspace->getKey())
            ->where('status', 'reconciled')
            ->pluck('source_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values();
        $userIds = $this->identities->sourceIdsFor($user, 'monitor');
        if ($workspaceIds->count() !== 1 || count($userIds) !== 1) {
            return null;
        }

        $monitorWorkspace = Workspace::query()->find($workspaceIds->sole());
        $productUser = User::query()->find($userIds[0]);
        if (! $monitorWorkspace instanceof Workspace
            || ! $productUser instanceof User
            || $monitorWorkspace->roleFor($productUser) === null) {
            return null;
        }

        return [$monitorWorkspace, $productUser];
    }

    /** @return array{Workspace, User}|null */
    private function managerContext(PlatformUser $user, CoreWorkspace $workspace): ?array
    {
        $context = $this->context($user, $workspace);

        return $context !== null && in_array($context[0]->roleFor($context[1]), ['owner', 'admin'], true)
            ? $context
            : null;
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function normalized(array $attributes): array
    {
        $attributes['published'] = filter_var($attributes['published'] ?? false, FILTER_VALIDATE_BOOL);
        $attributes['monitor_ids'] ??= [];

        return $attributes;
    }
}
