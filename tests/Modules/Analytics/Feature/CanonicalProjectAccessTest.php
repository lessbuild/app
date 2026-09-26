<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Projects\ManageCanonicalProjectMembership;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Jobs\GenerateReportExport;
use App\Modules\Analytics\Livewire\Dashboard\Overview;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Queries\Reporting\OverviewReport;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\Core\AnalyticsProjectLink;
use App\Modules\Analytics\Services\Core\AnalyticsResourceDestinationProvider;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceSearchProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class CanonicalProjectAccessTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_core_project_revocation_denies_direct_site_actions_and_existing_export_downloads(): void
    {
        $context = $this->context();
        $site = $context['site'];
        $user = $context['user'];
        $policy = app(SitePolicy::class);
        $this->assertTrue($policy->view($user, $site));
        $this->assertTrue($policy->manage($user, $site));

        Storage::fake('analytics-local');
        Storage::disk('analytics-local')->put('exports/existing.csv', 'private analytics');
        $export = $this->reportExport($context, $site, [
            'status' => 'completed',
            'file_path' => 'exports/existing.csv',
        ]);

        $this->revoke($context);

        $this->assertFalse($policy->view($user, $site));
        $this->assertFalse($policy->manage($user, $site));
        $this->assertTrue(app(AnalyticsWorkspaceAccess::class)->hasAccess($user, $context['workspace']));
        $this->assertSame(WorkspaceRole::Admin, $context['workspace']->roleFor($user->getKey()));
        $this->assertCount(0, app(AnalyticsProjectLink::class)->accessibleSites($context['platform_user'], $context['project']));
        $destinations = app(AnalyticsResourceDestinationProvider::class)->destinations($context['platform_user'], collect([$context['resource']]));
        $this->assertSame(ProjectResourceDestinationState::AccessChanged, $destinations[$context['resource']->getKey()]->state);

        foreach ([
            route('analytics.dashboard', ['site' => $site->getKey()]),
            route('analytics.sites.setup', $site),
            route('analytics.sites.settings', $site),
            route('analytics.goals.index', $site),
            route('analytics.reports.exports.record', [$site, $export]),
            route('analytics.reports.exports.download-record', [$site, $export]),
            route('analytics.reports.exports.show', 'existing-report-token'),
            route('analytics.reports.exports.download', 'existing-report-token'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }

        $this->withSession(['_token' => 'project-access-csrf']);
        $this->actingAs($user)->post(route('analytics.sites.verify', $site), ['_token' => 'project-access-csrf'])->assertForbidden();
        $this->actingAs($user)->post(route('analytics.reports.exports.store', $site), ['days' => 30, '_token' => 'project-access-csrf'])->assertForbidden();
        $this->actingAs($user)->post(route('analytics.reports.exports.retry', [$site, $export]), ['_token' => 'project-access-csrf'])->assertForbidden();
        $this->assertSame(1, ReportExport::query()->count());
    }

    public function test_site_selectors_counts_and_search_exclude_revoked_sites_before_result_limits(): void
    {
        $context = $this->context();
        $this->revoke($context);

        for ($index = 0; $index < 5; $index++) {
            $site = $context['workspace']->sites()->create([
                'name' => 'Site A hidden '.$index,
                'domains' => ['hidden-'.$index.'.example.test'],
                'timezone' => 'UTC',
            ]);
            $this->resource($context['project'], $site);
        }

        $workspaces = app(AnalyticsWorkspaceAccess::class)->workspacesFor($context['user']);
        $this->assertSame([$context['allowed_site']->getKey()], $workspaces->sole()->sites->modelKeys());

        $this->actingAs($context['user'])->get(route('analytics.dashboard'))
            ->assertOk()
            ->assertSee('Site Z allowed')
            ->assertDontSee('Site A restricted')
            ->assertDontSee('hidden-0.example.test');
        $this->actingAs($context['user'])->get(route('analytics.workspaces.index'))
            ->assertOk()->assertSee('1 website');

        $results = app(AnalyticsWorkspaceSearchProvider::class)->search($context['platform_user'], $context['core_workspace'], 'Site');
        $this->assertCount(1, $results);
        $this->assertSame('Site Z allowed', $results[0]->title);

        $context['grant']->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->assertCount(0, app(AnalyticsWorkspaceAccess::class)->workspacesFor($context['user']));
        $this->actingAs($context['user'])->get(route('analytics.workspaces.index'))
            ->assertOk()->assertDontSee($context['workspace']->name);
    }

    public function test_an_open_livewire_dashboard_rechecks_site_access_after_revocation(): void
    {
        $context = $this->context();
        $component = Livewire::actingAs($context['user'])
            ->withQueryParams(['site' => $context['site']->getKey()])
            ->test(Overview::class)
            ->assertSee('Site A restricted');

        $this->revoke($context);

        $component->call('$refresh')->assertForbidden();
    }

    public function test_workspace_export_omits_revoked_sites_and_their_events_goals_versions_and_reports(): void
    {
        $context = $this->context();
        $hiddenGoal = Goal::query()->create([
            'site_id' => $context['site']->getKey(),
            'name' => 'restricted-goal-name',
            'kind' => 'path',
            'match_type' => 'exact',
            'match_value' => '/restricted-goal',
            'active' => true,
        ]);
        foreach (['site' => '/restricted-event', 'allowed_site' => '/allowed-event'] as $key => $path) {
            AnalyticsEvent::query()->create([
                'site_id' => $context[$key]->getKey(),
                'event_id' => (string) Str::uuid(),
                'type' => 'pageview',
                'occurred_at' => now(),
                'received_at' => now(),
                'path' => $path,
            ]);
        }
        $hiddenExport = $this->reportExport($context, $context['site']);
        $this->revoke($context);

        $response = $this->actingAs($context['user'])->get(route('analytics.workspaces.data.export', $context['workspace']))->assertOk();
        $content = $response->streamedContent();
        $records = collect(explode("\n", trim($content)))->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));

        $this->assertStringContainsString('/allowed-event', $content);
        $this->assertStringNotContainsString('Site A restricted', $content);
        $this->assertStringNotContainsString('/restricted-event', $content);
        $this->assertStringNotContainsString('restricted-goal-name', $content);
        $this->assertFalse($records->contains(fn (array $record): bool => $record['type'] === 'goal_version'
            && $record['data']['goal_id'] === $hiddenGoal->getKey()));
        $this->assertFalse($records->contains(fn (array $record): bool => $record['type'] === 'report_export'
            && $record['data']['id'] === $hiddenExport->getKey()));
        $this->assertSame('currently_authorized_sites', $records->first()['data']['site_scope']);
    }

    public function test_a_queued_report_export_cannot_generate_after_its_requesters_project_access_is_revoked(): void
    {
        $context = $this->context();
        Storage::fake('analytics-local');
        $export = $this->reportExport($context, $context['site']);
        $this->revoke($context);

        (new GenerateReportExport($export->getKey()))->handle(app(OverviewReport::class));

        $this->assertSame('failed', $export->fresh()->status);
        $this->assertNull($export->fresh()->file_path);
        $this->assertSame([], Storage::disk('analytics-local')->allFiles());
    }

    public function test_canonical_access_does_not_bypass_native_roles_and_unmapped_and_legacy_sites_remain_accessible(): void
    {
        $context = $this->context();
        $policy = app(SitePolicy::class);
        $context['workspace']->users()->updateExistingPivot($context['user']->getKey(), ['role' => 'viewer']);
        $this->assertTrue($policy->view($context['user'], $context['site']));
        $this->assertFalse($policy->manage($context['user'], $context['site']));
        $this->assertFalse($policy->delete($context['user'], $context['site']));

        $this->revoke($context);
        $this->assertFalse($policy->view($context['user'], $context['site']));
        $this->assertTrue($policy->view($context['user'], $context['allowed_site']));

        config(['platform.products.analytics.auth_authority' => 'legacy']);
        $this->assertTrue($policy->view($context['user'], $context['site']));
        $this->assertCount(2, app(AnalyticsWorkspaceAccess::class)->sitesQuery($context['user'], $context['workspace'])->get());
    }

    public function test_paused_collection_keeps_site_management_available_until_project_access_is_revoked(): void
    {
        $context = $this->context();
        $context['site']->update(['collection_enabled' => false, 'collection_paused_at' => now()]);
        $context['resource']->update(['status' => 'paused']);
        $policy = app(SitePolicy::class);

        $this->assertTrue($policy->view($context['user'], $context['site']));
        $this->assertTrue($policy->manage($context['user'], $context['site']));
        $this->actingAs($context['user'])->get(route('analytics.sites.settings', $context['site']))->assertOk();

        $this->revoke($context);

        $this->assertFalse($policy->view($context['user'], $context['site']));
        $this->assertFalse($policy->manage($context['user'], $context['site']));
    }

    public function test_an_authorized_retry_records_its_current_requester_so_it_can_resume_an_old_failed_export(): void
    {
        $context = $this->context();
        $oldRequester = User::factory()->create();
        $export = $this->reportExport($context, $context['site'], [
            'status' => 'failed',
            'requested_by' => $oldRequester->getKey(),
        ]);
        Queue::fake();
        Storage::fake('analytics-local');

        $this->actingAs($context['user'])->withSession(['_token' => 'retry-csrf'])
            ->post(route('analytics.reports.exports.retry', [$context['site'], $export]), ['_token' => 'retry-csrf'])
            ->assertRedirect();

        $this->assertSame($context['user']->getKey(), $export->fresh()->requested_by);
        Queue::assertPushed(GenerateReportExport::class, fn (GenerateReportExport $job): bool => $job->exportId === $export->getKey());
        (new GenerateReportExport($export->getKey()))->handle(app(OverviewReport::class));
        $this->assertSame('completed', $export->fresh()->status);
        Storage::disk('analytics-local')->assertExists($export->fresh()->file_path);
    }

    public function test_inactive_and_cross_workspace_resource_mappings_cannot_fall_back_to_native_access(): void
    {
        $context = $this->context();
        $access = app(AnalyticsWorkspaceAccess::class);
        $context['resource']->update(['status' => 'inactive']);
        $this->assertFalse($access->hasSiteAccess($context['user'], $context['site']));
        $this->assertSame([$context['allowed_site']->getKey()], $access->sitesQuery($context['user'], $context['workspace'])->pluck('id')->all());

        $otherWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $context['owner']->getKey(),
            'name' => 'Other Core workspace',
            'slug' => 'other-core-workspace',
            'status' => 'active',
        ]);
        $context['resource']->update(['status' => 'active']);
        $context['project']->update(['workspace_id' => $otherWorkspace->getKey()]);
        $this->assertFalse($access->hasSiteAccess($context['user'], $context['site']));
        $this->assertSame([$context['allowed_site']->getKey()], $access->sitesQuery($context['user'], $context['workspace'])->pluck('id')->all());
    }

    public function test_imported_users_follow_reconciled_identity_maps_without_a_native_platform_backlink(): void
    {
        $context = $this->context();
        $context['user']->forceFill(['platform_user_id' => null])->save();
        $access = app(AnalyticsWorkspaceAccess::class);

        $this->assertTrue($access->hasAccess($context['user'], $context['workspace']));
        $this->assertTrue($access->hasSiteAccess($context['user'], $context['site']));
        $this->assertSame(WorkspaceRole::Admin, $access->roleFor($context['user'], $context['workspace']));

        LegacyIdentityMap::query()->where('source_product', 'analytics')
            ->where('source_entity', 'user')
            ->where('source_id', (string) $context['user']->getKey())
            ->update(['status' => 'needs_review']);

        $this->assertFalse($access->hasAccess($context['user'], $context['workspace']));
        $this->assertFalse($access->hasSiteAccess($context['user'], $context['site']));
    }

    public function test_project_links_apply_the_site_limit_after_native_access_checks(): void
    {
        $context = $this->context();
        $context['resource']->update(['status' => 'archived']);
        $privateWorkspace = Workspace::query()->create(['name' => 'Unrelated source workspace']);

        for ($index = 0; $index < 100; $index++) {
            $site = $privateWorkspace->sites()->create([
                'name' => 'Inaccessible site '.$index,
                'domains' => ['private-'.$index.'.example.test'],
                'timezone' => 'UTC',
            ]);
            $this->resource($context['project'], $site);
        }

        $this->resource($context['project'], $context['allowed_site']);
        $sites = app(AnalyticsProjectLink::class)->accessibleSites($context['platform_user'], $context['project']);

        $this->assertSame([$context['allowed_site']->getKey()], $sites->modelKeys());
    }

    public function test_project_link_and_destination_provider_queries_grow_by_workspace_instead_of_by_site(): void
    {
        $context = $this->context();
        $links = app(AnalyticsProjectLink::class);
        $destinations = app(AnalyticsResourceDestinationProvider::class);
        $resources = collect([$context['resource']]);
        $connections = [DB::connection('core'), DB::connection('analytics')];

        $measure = function () use ($context, $links, $destinations, &$resources, $connections): array {
            foreach ($connections as $connection) {
                $connection->enableQueryLog();
                $connection->flushQueryLog();
            }

            try {
                $sites = $links->accessibleSites($context['platform_user'], $context['project']);
                $resolved = $destinations->destinations($context['platform_user'], $resources);
                $queryCount = array_sum(array_map(fn ($connection): int => count($connection->getQueryLog()), $connections));

                return [$sites, $resolved, $queryCount];
            } finally {
                foreach ($connections as $connection) {
                    $connection->disableQueryLog();
                    $connection->flushQueryLog();
                }
            }
        };

        [, , $smallQueryCount] = $measure();
        for ($index = 0; $index < 100; $index++) {
            $site = $context['workspace']->sites()->create([
                'name' => 'Additional site '.$index,
                'domains' => ['additional-'.$index.'.example.test'],
                'timezone' => 'UTC',
            ]);
            $resources->push($this->resource($context['project'], $site));
        }

        [$sites, $resolved, $largeQueryCount] = $measure();
        $this->assertCount(100, $sites);
        $this->assertCount(101, $resolved);
        $this->assertTrue(collect($resolved)->every(fn ($destination): bool => $destination->state === ProjectResourceDestinationState::Available));
        $this->assertLessThanOrEqual($smallQueryCount + 8, $largeQueryCount);

        $this->revoke($context);
        $this->assertCount(0, $links->accessibleSites($context['platform_user'], $context['project']));
        $this->assertTrue(collect($destinations->destinations($context['platform_user'], $resources))
            ->every(fn ($destination): bool => $destination->state === ProjectResourceDestinationState::AccessChanged));
    }

    public function test_workspace_export_preserves_authorized_archived_site_history_without_restoring_interactive_access(): void
    {
        $context = $this->context();
        $site = $context['site'];
        AnalyticsEvent::query()->create([
            'site_id' => $site->getKey(),
            'event_id' => (string) Str::uuid(),
            'type' => 'pageview',
            'occurred_at' => now()->subDays(10),
            'received_at' => now()->subDays(10),
            'path' => '/retained-history',
        ]);
        $goal = Goal::query()->create([
            'site_id' => $site->getKey(),
            'name' => 'Retained historical goal',
            'kind' => 'path',
            'match_type' => 'exact',
            'match_value' => '/retained-history',
            'active' => false,
        ]);
        $export = $this->reportExport($context, $site);
        $context['project']->update(['status' => 'archived', 'archived_at' => now()]);
        $context['resource']->update(['status' => 'archived']);
        ProjectProduct::query()->where('project_id', $context['project']->getKey())->update(['status' => 'inactive']);

        $this->assertFalse(app(SitePolicy::class)->view($context['user'], $site));
        $this->actingAs($context['user'])->get(route('analytics.dashboard', ['site' => $site->getKey()]))->assertForbidden();
        $this->assertCount(0, app(AnalyticsProjectLink::class)->accessibleSites($context['platform_user'], $context['project']));
        $site->delete();
        $this->assertFalse(app(AnalyticsWorkspaceAccess::class)
            ->sitesQuery($context['user'], $context['workspace'], withTrashed: true)->whereKey($site->getKey())->exists());

        $response = $this->actingAs($context['user'])->get(route('analytics.workspaces.data.export', $context['workspace']))->assertOk();
        $content = $response->streamedContent();
        $records = collect(explode("\n", trim($content)))->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        $historicalSite = $records->first(fn (array $record): bool => $record['type'] === 'site' && $record['data']['id'] === $site->getKey());

        $this->assertNotNull($historicalSite);
        $this->assertNotNull($historicalSite['data']['deleted_at']);
        $this->assertStringContainsString('/retained-history', $content);
        $this->assertTrue($records->contains(fn (array $record): bool => $record['type'] === 'goal_version' && $record['data']['goal_id'] === $goal->getKey()));
        $this->assertTrue($records->contains(fn (array $record): bool => $record['type'] === 'report_export' && $record['data']['id'] === $export->getKey()));
        $this->assertTrue($records->first()['data']['includes_authorized_archived_site_history']);

        ProjectMembership::query()->where('project_id', $context['project']->getKey())
            ->where('user_id', $context['platform_user']->getKey())->update(['status' => 'revoked', 'revoked_at' => now()]);

        $revoked = $this->actingAs($context['user'])->get(route('analytics.workspaces.data.export', $context['workspace']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('/retained-history', $revoked);
        $this->assertStringNotContainsString('Retained historical goal', $revoked);
        $this->assertStringNotContainsString('Site A restricted', $revoked);
        $this->assertStringContainsString('Site Z allowed', $revoked);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        config(['platform.products.analytics.auth_authority' => 'core']);
        $owner = PlatformUser::query()->create([
            'name' => 'Workspace owner',
            'email' => 'owner@example.test',
            'email_normalized' => 'owner@example.test',
            'status' => 'active',
        ]);
        $platformUser = PlatformUser::query()->create([
            'name' => 'Analytics administrator',
            'email' => 'admin@example.test',
            'email_normalized' => 'admin@example.test',
            'status' => 'active',
        ]);
        $coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $owner->getKey(),
            'name' => 'Shared workspace',
            'slug' => 'shared-workspace',
            'status' => 'active',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $coreWorkspace->getKey(),
            'user_id' => $owner->getKey(),
            'role' => 'owner',
            'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $coreWorkspace->getKey(),
            'user_id' => $platformUser->getKey(),
            'role' => 'admin',
            'status' => 'active',
        ]);
        $grant = WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(),
            'product' => 'analytics',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $project = Project::query()->create([
            'workspace_id' => $coreWorkspace->getKey(),
            'created_by_user_id' => $owner->getKey(),
            'name' => 'Restricted project',
            'slug' => 'restricted-project',
            'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $project->getKey(),
            'user_id' => $platformUser->getKey(),
            'role' => 'admin',
            'status' => 'active',
        ]);
        ProjectProduct::query()->create([
            'project_id' => $project->getKey(),
            'product' => 'analytics',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $user->forceFill(['platform_user_id' => $platformUser->getKey()])->save();
        $workspace = Workspace::query()->create(['name' => 'Native Analytics workspace']);
        $workspace->users()->attach($user, ['role' => 'admin']);
        $site = $workspace->sites()->create([
            'name' => 'Site A restricted',
            'domains' => ['restricted.example.test'],
            'timezone' => 'UTC',
        ]);
        $allowedSite = $workspace->sites()->create([
            'name' => 'Site Z allowed',
            'domains' => ['allowed.example.test'],
            'timezone' => 'UTC',
        ]);
        foreach (['user' => [$user, $platformUser], 'workspace' => [$workspace, $coreWorkspace]] as $entity => [$source, $canonical]) {
            LegacyIdentityMap::query()->create([
                'source_product' => 'analytics',
                'source_entity' => $entity,
                'source_id' => (string) $source->getKey(),
                'canonical_entity' => $entity,
                'canonical_id' => $canonical->getKey(),
                'status' => 'reconciled',
            ]);
        }

        return [
            'owner' => $owner,
            'platform_user' => $platformUser,
            'core_workspace' => $coreWorkspace,
            'membership' => $membership,
            'grant' => $grant,
            'project' => $project,
            'resource' => $this->resource($project, $site),
            'user' => $user,
            'workspace' => $workspace,
            'site' => $site,
            'allowed_site' => $allowedSite,
        ];
    }

    private function resource(Project $project, Site $site): ProjectResource
    {
        return ProjectResource::query()->create([
            'project_id' => $project->getKey(),
            'product' => 'analytics',
            'resource_type' => 'site',
            'resource_id' => (string) $site->getKey(),
            'name' => $site->name,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $context */
    private function revoke(array $context): void
    {
        $this->assertTrue(app(ManageCanonicalProjectMembership::class)->revoke(
            $context['owner'], $context['core_workspace'], $context['project'], $context['membership']->getKey(),
        ));
    }

    /** @param array<string, mixed> $context
     * @param  array<string, mixed>  $attributes
     */
    private function reportExport(array $context, Site $site, array $attributes = []): ReportExport
    {
        return ReportExport::query()->create([
            'workspace_id' => $context['workspace']->getKey(),
            'site_id' => $site->getKey(),
            'requested_by' => $context['user']->getKey(),
            'token_hash' => hash('sha256', 'existing-report-token'),
            'status' => 'pending',
            'filters' => ['days' => 30],
            'expires_at' => now()->addHour(),
            ...$attributes,
        ]);
    }
}
