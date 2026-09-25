<?php

namespace App\Core\Http\Controllers;

use App\Core\Data\Notifications\WorkspaceNotification;
use App\Core\Data\Notifications\WorkspaceNotificationSeverity;
use App\Core\Enums\ProductKey;
use App\Core\Http\Requests\FilterWorkspaceNotificationsRequest;
use App\Core\Http\Requests\UpdateWorkspaceNotificationPreferenceRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceNotificationPreference;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\WorkspaceNotifications;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class WorkspaceNotificationInboxController
{
    public function __invoke(
        FilterWorkspaceNotificationsRequest $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceNotifications $notifications,
    ): View {
        $user = $this->user($request);
        $membership = $this->membership($user, $workspace, $access);
        $products = $this->products($membership);
        $projects = $this->projects($workspace, $user, $products);
        $filters = $request->validated();
        $selectedProject = $filters['project'] ?? 'all';

        if ($selectedProject !== 'all' && $selectedProject !== null) {
            abort_unless($projects->contains(fn (Project $project): bool => (string) $project->getKey() === $selectedProject), 404);
        }

        $feed = $notifications->forWorkspace($user, $workspace, $projects, $products);
        $items = $this->visibleItems($feed->notifications, $filters, $selectedProject);
        $visibleUnreadCount = $items->where('read', false)->count();
        $preferences = WorkspaceNotificationPreference::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->with('project:id,name')
            ->orderBy('product')
            ->orderBy('severity')
            ->get();

        return view('core::workspaces.notifications', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'contextProjects' => $projects->map(fn (Project $project): array => [
                'id' => (string) $project->getKey(),
                'name' => $project->name,
                'href' => route('core.projects.show', [$workspace, $project]),
            ]),
            'products' => $products,
            'projects' => $projects,
            'items' => $items,
            'threads' => $items->groupBy('threadKey'),
            'unavailableProducts' => $feed->unavailableProducts,
            'unreadCount' => $visibleUnreadCount,
            'visibleUnreadCount' => $visibleUnreadCount,
            'preferences' => $preferences,
            'filters' => [
                'state' => $filters['state'] ?? 'all',
                'product' => $filters['product'] ?? 'all',
                'severity' => $filters['severity'] ?? 'all',
                'project' => $selectedProject ?? 'all',
            ],
        ]);
    }

    public function markRead(
        Request $request,
        Workspace $workspace,
        string $notificationKey,
        WorkspaceProjectAccess $access,
        WorkspaceNotifications $notifications,
    ): RedirectResponse {
        $notification = $this->currentNotification($request, $workspace, $notificationKey, $access, $notifications);
        $user = $this->user($request);
        $this->storeReadState($user, $workspace, $notification);

        return $this->redirectToInbox($request, $workspace)->with('success', __('Notification marked as read.'));
    }

    public function markUnread(
        Request $request,
        Workspace $workspace,
        string $notificationKey,
        WorkspaceProjectAccess $access,
        WorkspaceNotifications $notifications,
    ): RedirectResponse {
        $user = $this->user($request);
        $notification = $this->currentNotification($request, $workspace, $notificationKey, $access, $notifications);

        DB::connection('core')->table('workspace_notification_reads')
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->where('notification_key', $notification->key)
            ->delete();

        return $this->redirectToInbox($request, $workspace)->with('success', __('Notification marked as unread.'));
    }

    public function markAllRead(
        FilterWorkspaceNotificationsRequest $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceNotifications $notifications,
    ): RedirectResponse {
        $user = $this->user($request);
        $membership = $this->membership($user, $workspace, $access);
        $products = $this->products($membership);
        $projects = $this->projects($workspace, $user, $products);
        $filters = $request->validated();
        $selectedProject = $filters['project'] ?? 'all';

        if ($selectedProject !== 'all' && $selectedProject !== null) {
            abort_unless($projects->contains(fn (Project $project): bool => (string) $project->getKey() === $selectedProject), 404);
        }

        $visibleItems = $this->visibleItems(
            $notifications->forWorkspace($user, $workspace, $projects, $products)->notifications,
            $filters,
            $selectedProject,
        )
            ->filter(fn (WorkspaceNotification $item): bool => ! $item->read)
            ->values();

        $now = now();
        $rows = $visibleItems->map(fn (WorkspaceNotification $notification): array => [
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'notification_key' => $notification->key,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            DB::connection('core')->table('workspace_notification_reads')->upsert(
                $rows,
                ['workspace_id', 'user_id', 'notification_key'],
                ['read_at', 'updated_at'],
            );
        }

        return $this->redirectToInbox($request, $workspace)->with('success', __('Visible notifications marked as read.'));
    }

    public function updatePreference(
        UpdateWorkspaceNotificationPreferenceRequest $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
    ): RedirectResponse {
        $user = $this->user($request);
        $membership = $this->membership($user, $workspace, $access);
        $data = $request->validated();
        $product = $data['product'];
        $severity = WorkspaceNotificationSeverity::from($data['severity']);
        $projectId = $data['project_id'] ?? null;
        $products = $this->products($membership);

        abort_unless(in_array($product, $products, true), 403);

        if ($projectId !== null) {
            $projects = $this->projects($workspace, $user, $products);
            $project = $projects->first(fn (Project $candidate): bool => (string) $candidate->getKey() === $projectId);
            abort_unless($project instanceof Project, 404);
            abort_unless($project->products->contains('product', $product), 403);
        }

        $scopeKey = WorkspaceNotifications::preferenceScopeKey($projectId, $product, $severity);
        $preference = WorkspaceNotificationPreference::query()->firstOrNew([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'scope_key' => $scopeKey,
        ]);
        $preference->forceFill([
            'id' => $preference->exists ? $preference->getKey() : (string) Str::ulid(),
            'project_id' => $projectId,
            'product' => $product,
            'severity' => $severity->value,
            'enabled' => filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN),
        ])->save();

        return $this->redirectToInbox($request, $workspace)->with('success', __('Inbox preference saved. Product alert delivery settings are unchanged.'));
    }

    public function destroyPreference(
        Request $request,
        Workspace $workspace,
        WorkspaceNotificationPreference $preference,
        WorkspaceProjectAccess $access,
    ): RedirectResponse {
        $user = $this->user($request);
        $this->membership($user, $workspace, $access);
        abort_unless((string) $preference->workspace_id === (string) $workspace->getKey()
            && (string) $preference->user_id === (string) $user->getKey(), 404);

        $preference->delete();

        return $this->redirectToInbox($request, $workspace)->with('success', __('Inbox preference reset. Product alert delivery settings are unchanged.'));
    }

    private function currentNotification(
        Request $request,
        Workspace $workspace,
        string $notificationKey,
        WorkspaceProjectAccess $access,
        WorkspaceNotifications $notifications,
    ): WorkspaceNotification {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $notificationKey) === 1, 404);

        $user = $this->user($request);
        $membership = $this->membership($user, $workspace, $access);
        $products = $this->products($membership);
        $feed = $notifications->forWorkspace($user, $workspace, $this->projects($workspace, $user, $products), $products);
        $notification = $feed->notifications->firstWhere('key', $notificationKey);

        abort_unless($notification instanceof WorkspaceNotification, 404);

        return $notification;
    }

    private function storeReadState(PlatformUser $user, Workspace $workspace, WorkspaceNotification $notification): void
    {
        $now = now();
        DB::connection('core')->table('workspace_notification_reads')->upsert([[
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'notification_key' => $notification->key,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['workspace_id', 'user_id', 'notification_key'], ['read_at', 'updated_at']);
    }

    private function redirectToInbox(Request $request, Workspace $workspace): RedirectResponse
    {
        return to_route('core.workspace.notifications', [
            'workspace' => $workspace,
            ...$request->only(['state', 'product', 'severity', 'project']),
        ]);
    }

    /**
     * @param  Collection<int, WorkspaceNotification>  $notifications
     * @param  array<string, mixed>  $filters
     * @return Collection<int, WorkspaceNotification>
     */
    private function visibleItems(Collection $notifications, array $filters, ?string $selectedProject = 'all'): Collection
    {
        return $notifications
            ->filter(fn (WorkspaceNotification $item): bool => ($filters['state'] ?? 'all') === 'all'
                || (($filters['state'] ?? 'all') === 'unread' && ! $item->read)
                || (($filters['state'] ?? 'all') === 'read' && $item->read))
            ->when(($filters['product'] ?? 'all') !== 'all', fn (Collection $items): Collection => $items->where('product', $filters['product']))
            ->when(($filters['severity'] ?? 'all') !== 'all', fn (Collection $items): Collection => $items->filter(
                fn (WorkspaceNotification $item): bool => $item->severity->value === $filters['severity'],
            ))
            ->when($selectedProject !== 'all' && $selectedProject !== null, fn (Collection $items): Collection => $items->where('projectId', $selectedProject))
            ->values();
    }

    private function user(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }

    private function membership(PlatformUser $user, Workspace $workspace, WorkspaceProjectAccess $access): WorkspaceMembership
    {
        return $access->activeMembership($user, $workspace) ?? abort(404);
    }

    /** @return list<string> */
    private function products(WorkspaceMembership $membership): array
    {
        return WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('product')
            ->filter(fn (string $product): bool => ProductKey::tryFrom($product) !== null)
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<string> $products
     * @return Collection<int, Project>
     */
    private function projects(Workspace $workspace, PlatformUser $user, array $products): Collection
    {
        if ($products === []) {
            return collect();
        }

        return Project::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $query) => $query
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereNull('revoked_at'))
            ->whereHas('products', fn (Builder $query) => $query
                ->whereIn('product', $products)
                ->where('status', 'active'))
            ->with(['products' => fn (Builder $query) => $query
                ->whereIn('product', $products)
                ->where('status', 'active')])
            ->orderBy('name')
            ->limit(500)
            ->get();
    }

    /** @return Collection<int, Workspace> */
    private function workspacesFor(PlatformUser $user): Collection
    {
        return Workspace::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $query) => $query->where('user_id', $user->getKey())->currentlyActive())
            ->orderBy('name')
            ->get();
    }
}
