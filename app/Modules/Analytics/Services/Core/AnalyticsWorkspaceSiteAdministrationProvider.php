<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\Analytics\WorkspaceAnalyticsSiteAdministrationProvider;
use App\Core\Data\Analytics\AnalyticsSiteDeletionOutcome;
use App\Core\Data\Analytics\AnalyticsSiteInput;
use App\Core\Data\Analytics\AnalyticsSiteSettings;
use App\Core\Data\Analytics\AnalyticsSiteSetup;
use App\Core\Data\Analytics\AnalyticsSiteSummary;
use App\Core\Data\Analytics\WorkspaceAnalyticsSiteSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Analytics\Actions\Sites\CreateSiteForWorkspace;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\SiteDeletionOperation;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Services\AnalyticsPlanAuthority;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use App\Modules\Analytics\Services\Deletion\AnalyticsSiteDeletionService;
use Illuminate\Database\LostConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;

final class AnalyticsWorkspaceSiteAdministrationProvider implements WorkspaceAnalyticsSiteAdministrationProvider
{
    public function __construct(
        private readonly AnalyticsCoreAdministrationAccess $nativeAccess,
        private readonly AnalyticsWorkspaceAccess $access,
        private readonly AnalyticsPlanAuthority $plans,
        private readonly CreateSiteForWorkspace $createSite,
        private readonly WorkspaceProjectAccess $projectAccess,
        private readonly AnalyticsSiteDeletionService $deletions,
    ) {}

    public function snapshot(PlatformUser $user, CoreWorkspace $workspace): WorkspaceAnalyticsSiteSnapshot
    {
        try {
            $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);
            $role = $this->nativeAccess->role($user, $nativeWorkspace);
            $plan = $this->plans->resolve($nativeWorkspace);
            $workspaceSiteCount = $nativeWorkspace->sites()->count();
            $visibleSiteCount = $this->nativeAccess->sites($user, $nativeWorkspace)->count();
            $siteLimitKnown = $plan->hasLimit('sites');
            $siteLimit = $plan->limit('sites');
            $canManage = $role?->canManageSites() === true;
            $canCreate = $canManage && $plan->available && $plan->allows('site_management')
                && $siteLimitKnown && ($siteLimit === null || $workspaceSiteCount < $siteLimit);
            $visibleProjects = $this->projectAccess->accessibleProductProjects($user, $workspace, 'analytics')->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $siteRows = $this->nativeAccess->sites($user, $nativeWorkspace)->orderBy('name')->orderBy('id')->limit(101)->get()
                ->take(100);
            $sites = $siteRows
                ->map(fn (Site $site): ?AnalyticsSiteSummary => $this->summary($user, $site, $visibleProjects))
                ->filter()->values()->all();

            return new WorkspaceAnalyticsSiteSnapshot(
                sites: $sites,
                canManageSites: $canManage,
                planAvailable: $plan->available,
                planName: $plan->planName,
                siteLimit: $siteLimitKnown ? $siteLimit : null,
                siteCount: $visibleSiteCount,
                detailRetentionDays: $plan->limits['retention_days'] ?? null,
                aggregateRetentionMonths: $plan->limits['aggregate_retention_months'] ?? null,
                exportRetentionHours: $plan->limits['export_retention_hours'] ?? null,
                canCreateSite: $canCreate,
                canRequestReport: $canManage && $plan->available && $plan->allows('site_management') && $plan->hasLimit('export_retention_hours'),
                truncated: $visibleSiteCount > 100,
            );
        } catch (LostConnectionException|PDOException) {
            return new WorkspaceAnalyticsSiteSnapshot([], available: false);
        }
    }

    public function siteSummary(PlatformUser $user, CoreWorkspace $workspace, string $siteId): ?AnalyticsSiteSummary
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);
        $site = $this->nativeAccess->site($user, $nativeWorkspace, $siteId, manage: true);
        $visibleProjects = $this->projectAccess->accessibleProductProjects($user, $workspace, 'analytics')
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return $this->summary($user, $site, $visibleProjects);
    }

    public function setup(PlatformUser $user, CoreWorkspace $workspace, string $siteId): ?AnalyticsSiteSetup
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);
        $site = $this->nativeAccess->site($user, $nativeWorkspace, $siteId, manage: true);

        return $this->setupFor($site);
    }

    public function create(PlatformUser $user, CoreWorkspace $workspace, AnalyticsSiteInput $input): AnalyticsSiteSetup
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);
        abort_unless($this->nativeAccess->role($user, $nativeWorkspace)?->canManageSites() === true, 403);
        $domain = $this->normalizeDomain($input->domain);
        $this->assertValidDomain($domain);
        $name = trim($input->name);
        abort_if($name === '' || mb_strlen($name) > 120, 422);
        abort_if(! in_array($input->timezone, timezone_identifiers_list(), true), 422);

        $site = DB::connection('analytics')->transaction(function () use ($user, $workspace, $nativeWorkspace, $name, $domain, $input): Site {
            $lockedWorkspace = Workspace::query()->whereKey($nativeWorkspace->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCurrentWorkspace($user, $workspace, $lockedWorkspace);
            abort_unless($this->nativeAccess->role($user, $lockedWorkspace)?->canManageSites() === true, 403);
            app(AnalyticsDeletionFence::class)->assertWorkspaceOpen($lockedWorkspace->getKey());
            $plan = $this->plans->resolve($lockedWorkspace);

            return $this->createSite->handle($lockedWorkspace, $plan, [
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
                'domains' => [$domain],
                'timezone' => $input->timezone,
            ]);
        }, attempts: 3);

        return $this->setupFor($site);
    }

    public function update(PlatformUser $user, CoreWorkspace $workspace, string $siteId, AnalyticsSiteSettings $settings): void
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);
        DB::connection('analytics')->transaction(function () use ($user, $workspace, $nativeWorkspace, $siteId, $settings): void {
            $lockedWorkspace = Workspace::query()->whereKey($nativeWorkspace->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCurrentWorkspace($user, $workspace, $lockedWorkspace);
            app(AnalyticsDeletionFence::class)->assertWorkspaceOpen($lockedWorkspace->getKey());
            $site = Site::query()->where('workspace_id', $lockedWorkspace->getKey())->whereKey($siteId)->lockForUpdate()->firstOrFail();
            app(AnalyticsDeletionFence::class)->assertSiteOpen($site->getKey());
            $this->nativeAccess->site($user, $lockedWorkspace, (string) $site->getKey(), manage: true);
            $domains = collect($settings->domains)->map(fn (string $domain): string => $this->normalizeDomain($domain))->filter()->unique()->values()->all();
            abort_if($domains === [], 422);
            foreach ($domains as $domain) {
                $this->assertValidDomain($domain);
            }
            abort_if(trim($settings->name) === '' || mb_strlen(trim($settings->name)) > 120, 422);
            abort_if(! in_array($settings->timezone, timezone_identifiers_list(), true), 422);
            abort_if(count($settings->excludedPaths) > 50 || collect($settings->excludedPaths)->contains(fn (string $path): bool => mb_strlen($path) > 2048), 422);

            if ($site->events()->exists() && $settings->timezone !== $site->timezone) {
                throw ValidationException::withMessages(['timezone' => 'The reporting timezone cannot change after collection begins.']);
            }
            $domainChanged = $domains !== ($site->domains ?? []);
            $site->update([
                'name' => trim($settings->name), 'domains' => $domains, 'timezone' => $settings->timezone,
                'excluded_paths' => collect($settings->excludedPaths)->map(fn (string $path): string => trim($path))
                    ->filter()->map(fn (string $path): string => Str::startsWith($path, '/') || Str::startsWith($path, '*') ? $path : '/'.$path)
                    ->take(50)->values()->all(),
                'collection_enabled' => $settings->collectionEnabled,
                'collection_paused_at' => $settings->collectionPaused ? ($site->collection_paused_at ?? now()) : null,
                'verified_at' => $domainChanged ? null : $site->verified_at,
            ]);
        }, attempts: 3);
    }

    public function verify(PlatformUser $user, CoreWorkspace $workspace, string $siteId, string $token): bool
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);

        return DB::connection('analytics')->transaction(function () use ($user, $workspace, $nativeWorkspace, $siteId, $token): bool {
            $lockedWorkspace = Workspace::query()->whereKey($nativeWorkspace->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCurrentWorkspace($user, $workspace, $lockedWorkspace);
            app(AnalyticsDeletionFence::class)->assertWorkspaceOpen($lockedWorkspace->getKey());
            $site = Site::query()->where('workspace_id', $lockedWorkspace->getKey())->whereKey($siteId)->lockForUpdate()->firstOrFail();
            app(AnalyticsDeletionFence::class)->assertSiteOpen($site->getKey());
            $this->nativeAccess->site($user, $lockedWorkspace, (string) $site->getKey(), manage: true);
            $valid = hash_equals((string) $site->verification_token, trim($token));
            $manualVerificationAllowed = app()->environment(['local', 'testing']);
            if (! $valid || (! $manualVerificationAllowed && ! $site->hasVerificationRecord($token))) {
                return false;
            }
            $site->update(['verified_at' => now()]);

            return true;
        }, attempts: 3);
    }

    public function delete(PlatformUser $user, CoreWorkspace $workspace, string $siteId, string $confirmation): AnalyticsSiteDeletionOutcome
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);
        abort_unless($this->nativeAccess->role($user, $nativeWorkspace)?->value === 'owner', 403);
        $site = $this->nativeAccess->site($user, $nativeWorkspace, $siteId, manage: true);
        abort_unless(app(SitePolicy::class)->delete($user, $site), 403);

        return $this->deletions->request($user, $site, $confirmation,
            (string) $workspace->getKey(), (string) $user->getKey());
    }

    public function retryDeletion(PlatformUser $user, CoreWorkspace $workspace, string $requestId): AnalyticsSiteDeletionOutcome
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);
        $operation = SiteDeletionOperation::query()->whereKey($requestId)->firstOrFail();
        abort_unless($operation->canonical_workspace_id === (string) $workspace->getKey()
            && $operation->workspace_source_id === (string) $nativeWorkspace->getKey(), 404);

        return $this->deletions->retry($requestId, $user);
    }

    public function deletionStatus(PlatformUser $user, CoreWorkspace $workspace, string $requestId): AnalyticsSiteDeletionOutcome
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);
        $operation = SiteDeletionOperation::query()->whereKey($requestId)->firstOrFail();
        abort_unless($operation->canonical_workspace_id === (string) $workspace->getKey()
            && $operation->workspace_source_id === (string) $nativeWorkspace->getKey(), 404);

        return $this->deletions->status($requestId, $user);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);

        return trim((string) strtok($domain, '/'));
    }

    private function assertCurrentWorkspace(PlatformUser $user, CoreWorkspace $coreWorkspace, Workspace $nativeWorkspace): void
    {
        $current = $this->nativeAccess->workspace($user, $coreWorkspace);
        abort_unless((string) $current->getKey() === (string) $nativeWorkspace->getKey(), 404);
    }

    private function assertValidDomain(string $domain): void
    {
        abort_unless((bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain), 422);
    }

    /** @param list<string> $visibleProjectIds */
    private function summary(PlatformUser $user, Site $site, array $visibleProjectIds): ?AnalyticsSiteSummary
    {
        if (! app(SitePolicy::class)->view($user, $site)) {
            return null;
        }
        $mapped = $visibleProjectIds !== [] && ProjectResource::query()
            ->whereIn('project_id', $visibleProjectIds)
            ->where('product', 'analytics')->where('resource_type', 'site')
            ->where('resource_id', (string) $site->getKey())->where('status', 'active')->exists();
        $role = $this->access->roleFor($user, $site->workspace);

        return new AnalyticsSiteSummary(
            id: (string) $site->getKey(), name: (string) $site->name, domains: array_values($site->domains ?? []),
            excludedPaths: array_values($site->excluded_paths ?? []),
            timezone: (string) $site->timezone, verified: $site->isVerified(),
            collectionEnabled: (bool) $site->collection_enabled, collectionPaused: $site->collection_paused_at !== null,
            collectionAvailable: $site->isCollectionAvailable(), canManage: $role?->canManageSites() === true,
            linkedToSharedProject: $mapped, hasEvents: $site->events()->exists(),
            lastEventAt: $site->last_event_at?->toImmutable()->utc(), lastProcessedAt: $site->last_processed_at?->toImmutable()->utc(),
            canDeleteSite: $role === WorkspaceRole::Owner && app(SitePolicy::class)->delete($user, $site),
            deleteConfirmation: $site->slug,
        );
    }

    private function setupFor(Site $site): AnalyticsSiteSetup
    {
        $analyticsUrl = rtrim((string) config('platform.products.analytics.url'), '/');
        $trackerUrl = $analyticsUrl !== '' ? $analyticsUrl.'/tracker/v1.js' : null;

        return new AnalyticsSiteSetup(
            id: (string) $site->getKey(), name: (string) $site->name, publicId: (string) $site->public_id,
            domain: (string) ($site->domains[0] ?? ''), verificationRecordName: $site->verificationRecordName(),
            verificationToken: (string) $site->verification_token,
            trackerSnippet: $trackerUrl === null ? null : sprintf('<script defer src="%s" data-site="%s"></script>', $trackerUrl, $site->public_id),
            verified: $site->isVerified(), hasEvents: $site->events()->exists(),
        );
    }
}
