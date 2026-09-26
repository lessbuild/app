<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\WorkspaceCustomerStatusManagementProvider;
use App\Core\Data\Status\WorkspaceCustomerStatusManagement;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Actions\Observability\CreateStatusIncidentAction;
use App\Modules\Deployer\Actions\Observability\CreateStatusPageAction;
use App\Modules\Deployer\Actions\Observability\DeleteStatusPageAction;
use App\Modules\Deployer\Actions\Observability\UpdateStatusIncidentAction;
use App\Modules\Deployer\Actions\Observability\UpdateStatusPageAction;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\StatusIncident;
use App\Modules\Deployer\Models\StatusPage;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;

/** Bridges Core status-page administration to Deployer-owned rows and guarded actions. */
final class DeployerWorkspaceCustomerStatusManagementProvider implements WorkspaceCustomerStatusManagementProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $workspaceAccess,
        private readonly Entitlements $entitlements,
        private readonly CreateStatusPageAction $createPage,
        private readonly UpdateStatusPageAction $updatePage,
        private readonly DeleteStatusPageAction $deletePage,
        private readonly CreateStatusIncidentAction $createIncident,
        private readonly UpdateStatusIncidentAction $updateIncident,
    ) {}

    public function forWorkspace(PlatformUser $user, Workspace $workspace): ?WorkspaceCustomerStatusManagement
    {
        try {
            $context = $this->context($user, $workspace);
            if ($context === null) {
                return null;
            }

            [$organization, $productUser] = $context;
            $pages = app(DeployerProjectAccess::class)->statusPages($organization->statusPages(), $productUser)
                ->with('websites:id,name')
                ->withCount(['subscriptions as confirmed_subscribers_count' => fn ($query) => $query->whereNotNull('verified_at')])
                ->latest()
                ->get();
            $incidents = StatusIncident::query()
                ->whereIn('status_page_id', $pages->modelKeys())
                ->with('statusPage:id,name,organization_id')
                ->latest('starts_at')
                ->limit(50)
                ->get();

            return new WorkspaceCustomerStatusManagement(
                websites: app(DeployerProjectAccess::class)->websites($organization->websites(), $productUser)->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($website): array => ['id' => (string) $website->getKey(), 'name' => (string) $website->name])
                    ->all(),
                pages: $pages->map(fn (StatusPage $page): array => [
                    'id' => (string) $page->getKey(),
                    'name' => (string) $page->name,
                    'slug' => (string) $page->slug,
                    'description' => $page->description,
                    'published' => (bool) $page->is_published,
                    'website_ids' => $page->websites->map(fn ($website): string => (string) $website->getKey())->all(),
                    'website_names' => $page->websites->map(fn ($website): string => (string) ($website->pivot->display_name ?: $website->name))->all(),
                    'subscriber_count' => (int) $page->confirmed_subscribers_count,
                    'public_url' => route('core.status-pages.show', ['product' => 'deployer', 'slug' => $page->slug]),
                ])->all(),
                incidents: $incidents->map(fn (StatusIncident $incident): array => [
                    'id' => (string) $incident->getKey(),
                    'page_id' => (string) $incident->status_page_id,
                    'page_name' => (string) ($incident->statusPage?->name ?? __('Unavailable status page')),
                    'kind' => (string) $incident->kind,
                    'status' => (string) $incident->status,
                    'severity' => (string) $incident->severity,
                    'title' => (string) $incident->title,
                    'message' => (string) $incident->message,
                    'root_cause' => $incident->root_cause,
                    'remediation' => $incident->remediation,
                    'follow_up' => $incident->follow_up,
                    'starts_at' => $this->date($incident->starts_at),
                    'ends_at' => $incident->ends_at === null ? null : $this->date($incident->ends_at),
                ])->all(),
                canManage: $organization->permits($productUser, 'manage'),
                featureAvailable: $this->entitlements->allows($organization, 'status_pages'),
            );
        } catch (LostConnectionException|QueryException) {
            return null;
        }
    }

    public function createPage(PlatformUser $user, Workspace $workspace, array $attributes): bool
    {
        $context = $this->managerContext($user, $workspace);
        if ($context === null || ! $this->ownsWebsites($context[0], $context[1], $attributes['website_ids'] ?? [])) {
            return false;
        }

        [$organization, $productUser] = $context;
        $attributes['slug'] = (string) ($attributes['slug'] ?? '');

        $this->createPage->handle($organization, $productUser, $attributes);

        return true;
    }

    public function updatePage(PlatformUser $user, Workspace $workspace, string $pageId, array $attributes): bool
    {
        $context = $this->managerContext($user, $workspace);
        if ($context === null || ! $this->ownsWebsites($context[0], $context[1], $attributes['website_ids'] ?? [])) {
            return false;
        }

        [$organization, $productUser] = $context;
        $page = app(DeployerProjectAccess::class)->statusPages($organization->statusPages(), $productUser)->whereKey($pageId)->first();
        if (! $page instanceof StatusPage) {
            return false;
        }

        $this->updatePage->handle($page, $attributes);

        return true;
    }

    public function deletePage(PlatformUser $user, Workspace $workspace, string $pageId): bool
    {
        $context = $this->managerContext($user, $workspace);
        if ($context === null) {
            return false;
        }

        [$organization, $productUser] = $context;
        $page = app(DeployerProjectAccess::class)->statusPages($organization->statusPages(), $productUser)->whereKey($pageId)->first();
        if (! $page instanceof StatusPage) {
            return false;
        }

        $this->deletePage->handle($page);

        return true;
    }

    public function createIncident(PlatformUser $user, Workspace $workspace, array $attributes): bool
    {
        $context = $this->managerContext($user, $workspace);
        if ($context === null || ! app(DeployerProjectAccess::class)->statusPages($context[0]->statusPages(), $context[1])->whereKey($attributes['status_page_id'] ?? null)->exists()) {
            return false;
        }

        [$organization, $productUser] = $context;
        $this->createIncident->handle($organization, $productUser, $attributes);

        return true;
    }

    public function updateIncident(PlatformUser $user, Workspace $workspace, string $incidentId, array $attributes): bool
    {
        $context = $this->managerContext($user, $workspace);
        if ($context === null) {
            return false;
        }

        [$organization, $productUser] = $context;
        $incident = StatusIncident::query()
            ->whereKey($incidentId)
            ->whereHas('statusPage', fn ($query) => app(DeployerProjectAccess::class)->statusPages($query->where('organization_id', $organization->getKey()), $productUser))
            ->first();
        if (! $incident instanceof StatusIncident) {
            return false;
        }

        $this->updateIncident->handle($incident, $attributes);

        return true;
    }

    /** @return array{Organization, User}|null */
    private function context(PlatformUser $user, Workspace $workspace): ?array
    {
        if (! config('platform.products.deployer.enabled', false)) {
            return null;
        }

        $membership = $this->workspaceAccess->activeMembership($user, $workspace);
        if ($membership === null || ! $this->workspaceAccess->hasProductAccess($membership, 'deployer')) {
            return null;
        }

        $organizationIds = LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', 'organization')
            ->where('canonical_entity', 'workspace')
            ->where('canonical_id', (string) $workspace->getKey())
            ->where('status', 'reconciled')
            ->pluck('source_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values();
        $userIds = $this->identities->sourceIdsFor($user, 'deployer');
        if ($organizationIds->count() !== 1 || count($userIds) !== 1) {
            return null;
        }

        $organization = Organization::query()->find($organizationIds->sole());
        $productUser = User::query()->find($userIds[0]);
        if (! $organization instanceof Organization
            || ! $productUser instanceof User
            || ! $organization->permits($productUser, 'view')) {
            return null;
        }

        $productUser->setAttribute('current_organization_id', $organization->getKey());
        $productUser->setRelation('currentOrganization', $organization);

        return [$organization, $productUser];
    }

    /** @return array{Organization, User}|null */
    private function managerContext(PlatformUser $user, Workspace $workspace): ?array
    {
        $context = $this->context($user, $workspace);

        return $context !== null && $context[0]->permits($context[1], 'manage') ? $context : null;
    }

    /** @param mixed $websiteIds @return bool */
    private function ownsWebsites(Organization $organization, User $productUser, mixed $websiteIds): bool
    {
        if (! is_array($websiteIds) || $websiteIds === []) {
            return false;
        }

        $requested = collect($websiteIds)->map(static fn ($id): string => (string) $id)->unique()->values();
        $owned = app(DeployerProjectAccess::class)->websites($organization->websites(), $productUser)->whereKey($requested->all())->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values();

        return $requested->count() === $owned->count() && $requested->diff($owned)->isEmpty();
    }

    private function date(CarbonInterface $date): string
    {
        return $date->copy()->utc()->format('Y-m-d\\TH:i');
    }
}
