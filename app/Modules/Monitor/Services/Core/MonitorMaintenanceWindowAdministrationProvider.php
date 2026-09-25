<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceMonitorMaintenanceWindowAdministrationProvider;
use App\Core\Data\Monitor\MonitorMaintenanceWindowSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Monitor\Models\MaintenanceWindow;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ChangeMaintenanceWindow;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Core-rendered maintenance administration delegated to Monitor's native action. */
final class MonitorMaintenanceWindowAdministrationProvider implements WorkspaceMonitorMaintenanceWindowAdministrationProvider
{
    private readonly MonitorAdministrationContext $context;

    public function __construct(
        LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $workspaceAccess,
        ProductWorkspaceAccess $productAccess,
        private readonly ChangeMaintenanceWindow $changes,
    ) {
        $this->context = new MonitorAdministrationContext($identities, $workspaceAccess, $productAccess);
    }

    public function snapshot(PlatformUser $user, CoreWorkspace $workspace, array $filters = []): ?MonitorMaintenanceWindowSnapshot
    {
        $this->context->resetReferences();
        $resolved = $this->context->resolve($user, $workspace);
        if ($resolved === null) {
            return null;
        }
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $resolved;

        $canManage = $this->canManage($user, $workspace, $actor, $sourceWorkspace);
        $page = max(1, min(100000, (int) ($filters['maintenance_page'] ?? 1)));
        $items = $sourceWorkspace->maintenanceWindows()
            ->latest('starts_at')->latest('id')
            ->paginate(20, ['*'], 'maintenance_page', $page)->withQueryString()
            ->through(function (MaintenanceWindow $window) use ($sourceWorkspace, $canManage): array {
                $version = $this->version($window);

                return [
                    'reference' => $this->context->reference('maintenance-window', $window->getKey(), $sourceWorkspace),
                    'form_key' => hash_hmac('sha256', 'monitor-maintenance-window:'.$sourceWorkspace->getKey().':'.$window->getKey(), (string) config('app.key')),
                    'version' => $version,
                    'can_mutate' => $canManage && $version !== null,
                    'name' => (string) $window->name,
                    'reason' => (string) ($window->reason ?? ''),
                    'starts_at' => $window->starts_at->setTimezone('UTC')->format('Y-m-d\\TH:i'),
                    'ends_at' => $window->ends_at->setTimezone('UTC')->format('Y-m-d\\TH:i'),
                    'starts_at_label' => $window->starts_at->setTimezone('UTC')->format('Y-m-d H:i').' UTC',
                    'ends_at_label' => $window->ends_at->setTimezone('UTC')->format('Y-m-d H:i').' UTC',
                ];
            });

        return new MonitorMaintenanceWindowSnapshot($items, $canManage);
    }

    public function saveWindow(PlatformUser $user, CoreWorkspace $workspace, ?string $windowReference, array $data): MonitorMutationResult
    {
        $values = Validator::make($data, [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\\TH:i', 'after:starts_at'],
        ])->validate();
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $this->assertCoreManager($user, $workspace);

        $id = $windowReference === null
            ? null
            : $this->context->sourceId($windowReference, 'maintenance-window', $sourceWorkspace);
        $version = $data['version'] ?? null;
        abort_unless($id === null ? $version === null : is_string($version) && preg_match('/\\A\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?\\z/', $version) === 1, 422);

        // Context::mutate has already reserved the Monitor write transaction here;
        // keep the raw version comparison inside that transaction for SQLite too.
        $saved = $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($id, $version, $values, $actor, $user, $workspace): MaintenanceWindow {
            $this->assertCoreManager($user, $workspace);
            $window = null;
            if ($id !== null) {
                $window = MaintenanceWindow::query()->whereBelongsTo($locked)->lockForUpdate()->findOrFail($id);
                $currentVersion = $this->version($window);
                abort_unless($currentVersion !== null && hash_equals($currentVersion, (string) $version), 409, 'This maintenance window changed. Reload the page before saving.');
            }

            $saved = $this->changes->save($locked, $actor, $values, $window);
            abort_unless((string) $saved->workspace_id === (string) $locked->getKey(), 404);
            $this->assertCoreManager($user, $workspace);

            return $saved;
        });

        return new MonitorMutationResult(true, $id === null ? 'Maintenance window scheduled.' : 'Maintenance window updated.',
            $this->context->reference('maintenance-window', $saved->getKey(), $sourceWorkspace));
    }

    public function deleteWindow(PlatformUser $user, CoreWorkspace $workspace, string $windowReference, string $version, bool $confirmRemove): MonitorMutationResult
    {
        abort_unless(preg_match('/\\A\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?\\z/', $version) === 1, 422);
        abort_unless($confirmRemove, 422, 'Confirm that you want to remove this maintenance window.');
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $this->assertCoreManager($user, $workspace);
        $id = $this->context->sourceId($windowReference, 'maintenance-window', $sourceWorkspace);

        $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($id, $version, $actor, $user, $workspace): void {
            $this->assertCoreManager($user, $workspace);
            $window = MaintenanceWindow::query()->whereBelongsTo($locked)->lockForUpdate()->findOrFail($id);
            $currentVersion = $this->version($window);
            abort_unless($currentVersion !== null && hash_equals($currentVersion, $version), 409, 'This maintenance window changed. Reload the page before deleting.');

            $this->changes->delete($locked, $actor, $window);
            $this->assertCoreManager($user, $workspace);
        });

        return new MonitorMutationResult(true, 'Maintenance window removed.');
    }

    /** @return array{workspace: Workspace, user: User} */
    private function requiredContext(PlatformUser $user, CoreWorkspace $workspace): array
    {
        $resolved = $this->context->resolve($user, $workspace);
        abort_if($resolved === null, 404);

        return $resolved;
    }

    private function assertCoreManager(PlatformUser $user, CoreWorkspace $workspace): void
    {
        abort_unless($this->workspaceAccess->canManageWorkspace($user, $workspace), 403);
    }

    private function canManage(PlatformUser $user, CoreWorkspace $workspace, User $actor, Workspace $sourceWorkspace): bool
    {
        return $this->workspaceAccess->canManageWorkspace($user, $workspace)
            && $actor->email_verified_at !== null
            && Gate::forUser($actor)->allows('update', $sourceWorkspace);
    }

    private function version(MaintenanceWindow $window): ?string
    {
        $version = $window->getRawOriginal('updated_at');
        if (! is_string($version)
            || preg_match('/\\A\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?\\z/', $version) !== 1) {
            return null;
        }

        return $version;
    }
}
