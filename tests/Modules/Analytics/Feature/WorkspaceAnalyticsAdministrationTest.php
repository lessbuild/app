<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Data\Analytics\AnalyticsGoalInput;
use App\Core\Data\Analytics\AnalyticsSiteSettings;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Jobs\GenerateReportExport;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceDataAdministrationProvider;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceGoalAdministrationProvider;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceSiteAdministrationProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class WorkspaceAnalyticsAdministrationTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_core_site_settings_preserve_privacy_controls_and_revoke_verification_when_domains_change(): void
    {
        [$platformUser, $coreWorkspace, $analyticsWorkspace, $site] = $this->mappedOwnerFixture();
        $provider = app(AnalyticsWorkspaceSiteAdministrationProvider::class);

        $setup = $provider->setup($platformUser, $coreWorkspace, (string) $site->getKey());
        $this->assertSame($site->verification_token, $setup?->verificationToken);
        $this->assertStringContainsString('data-site="'.$site->public_id.'"', (string) $setup?->trackerSnippet);
        $this->assertTrue($provider->verify($platformUser, $coreWorkspace, (string) $site->getKey(), $site->verification_token));

        $provider->update($platformUser, $coreWorkspace, (string) $site->getKey(), new AnalyticsSiteSettings(
            name: 'Updated public site', domains: ['www.example.test'], timezone: 'UTC',
            excludedPaths: ['/private/*', '/account'], collectionEnabled: true, collectionPaused: false,
        ));

        $site->refresh();
        $this->assertSame(['www.example.test'], $site->domains);
        $this->assertSame(['/private/*', '/account'], $site->excluded_paths);
        $this->assertNull($site->verified_at);
        $this->assertTrue($site->collection_enabled);
        $this->assertSame(['/private/*', '/account'], $provider->snapshot($platformUser, $coreWorkspace)->sites[0]->excludedPaths);
    }

    public function test_site_settings_cannot_reinterpret_existing_event_timestamps_with_a_new_timezone(): void
    {
        [$platformUser, $coreWorkspace, , $site] = $this->mappedOwnerFixture();
        $site->events()->create([
            'event_id' => (string) Str::ulid(), 'name' => 'page_view', 'path' => '/',
            'occurred_at' => now(), 'received_at' => now(), 'properties' => [],
        ]);

        try {
            app(AnalyticsWorkspaceSiteAdministrationProvider::class)->update(
                $platformUser, $coreWorkspace, (string) $site->getKey(),
                new AnalyticsSiteSettings('Timezone change', ['example.test'], 'America/Los_Angeles', [], true, false),
            );
            $this->fail('A site with collected events must retain its reporting timezone.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('timezone', $exception->errors());
        }
        $this->assertSame('UTC', $site->fresh()->timezone);
    }

    public function test_goal_mutations_keep_native_version_history_and_rebuild_derived_reports(): void
    {
        [$platformUser, $coreWorkspace, , $site] = $this->mappedOwnerFixture();
        $provider = app(AnalyticsWorkspaceGoalAdministrationProvider::class);

        $provider->create($platformUser, $coreWorkspace, (string) $site->getKey(), new AnalyticsGoalInput(
            'Signup complete', 'path', 'exact', '/thanks', true,
        ));
        $goal = Goal::query()->sole();
        $provider->update($platformUser, $coreWorkspace, (string) $site->getKey(), (string) $goal->getKey(), new AnalyticsGoalInput(
            'Signup complete', 'event', 'exact', 'signup_complete', false,
        ));

        $this->assertDatabaseCount('goal_versions', 2, 'analytics');
        $versions = $goal->versions()->orderBy('id')->get();
        $this->assertSame('path', $versions[0]->kind);
        $this->assertNotNull($versions[0]->effective_to);
        $this->assertSame('event', $versions[1]->kind);
        $this->assertFalse($goal->fresh()->active);

        $provider->delete($platformUser, $coreWorkspace, (string) $site->getKey(), (string) $goal->getKey());
        $this->assertDatabaseMissing('goals', ['id' => $goal->getKey()], 'analytics');
    }

    public function test_core_product_grant_revocation_blocks_native_site_changes(): void
    {
        [$platformUser, $coreWorkspace, , $site, $grantId] = $this->mappedOwnerFixture();
        DB::connection('core')->table('workspace_product_access')->where('id', $grantId)->update([
            'status' => 'revoked', 'revoked_at' => now(),
        ]);

        try {
            app(AnalyticsWorkspaceSiteAdministrationProvider::class)->update(
                $platformUser, $coreWorkspace, (string) $site->getKey(),
                new AnalyticsSiteSettings('Unauthorized', ['example.test'], 'UTC', [], false, false),
            );
            $this->fail('A revoked Analytics product grant must block native mutation.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertContains($exception->getStatusCode(), [403, 404]);
        }

        $this->assertSame('Mapped site', $site->fresh()->name);
    }

    public function test_report_export_lists_only_safe_metadata_and_reauthorizes_private_downloads(): void
    {
        [$platformUser, $coreWorkspace, , $site] = $this->mappedOwnerFixture();
        $provider = app(AnalyticsWorkspaceDataAdministrationProvider::class);
        $provider->requestReport($platformUser, $coreWorkspace, (string) $site->getKey(), [
            'days' => 30, 'path' => null, 'source' => null, 'campaign' => null, 'device' => null,
        ]);
        $export = ReportExport::query()->sole();
        $this->assertSame('pending', $export->status);
        $this->assertSame(64, strlen($export->token_hash));
        Queue::assertPushed(GenerateReportExport::class);

        Storage::fake('analytics-local');
        Storage::disk('analytics-local')->put('exports/'.$export->getKey().'.csv', "metric,value\nvisits,1\n");
        $export->update([
            'status' => 'completed', 'file_path' => 'exports/'.$export->getKey().'.csv', 'completed_at' => now(),
        ]);
        $snapshot = $provider->snapshot($platformUser, $coreWorkspace);
        $this->assertCount(1, $snapshot->reports);
        $this->assertFalse(property_exists($snapshot->reports[0], 'token_hash'));
        $this->assertFalse(property_exists($snapshot->reports[0], 'file_path'));
        $this->assertTrue($snapshot->reports[0]->downloadAvailable);

        $download = $provider->downloadReport($platformUser, $coreWorkspace, (string) $site->getKey(), (string) $export->getKey());
        $this->assertSame('private, no-store', $download->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
    }

    public function test_site_index_is_bounded_while_authorized_settings_lookup_reaches_later_sites(): void
    {
        [$platformUser, $coreWorkspace, $analyticsWorkspace] = $this->mappedOwnerFixture();
        $lastSite = null;
        for ($index = 1; $index <= 100; $index++) {
            $lastSite = $analyticsWorkspace->sites()->create([
                'name' => 'Site '.$index, 'domains' => ['site-'.$index.'.example.test'], 'timezone' => 'UTC',
            ]);
        }

        $provider = app(AnalyticsWorkspaceSiteAdministrationProvider::class);
        $snapshot = $provider->snapshot($platformUser, $coreWorkspace);
        $this->assertCount(100, $snapshot->sites);
        $this->assertTrue($snapshot->truncated);
        $this->assertSame((string) $lastSite->getKey(), $provider->siteSummary($platformUser, $coreWorkspace, (string) $lastSite->getKey())?->id);
    }

    public function test_current_plan_cannot_revive_a_report_export_past_its_persisted_expiry(): void
    {
        [$platformUser, $coreWorkspace, $analyticsWorkspace, $site] = $this->mappedOwnerFixture();
        Storage::fake('analytics-local');
        Storage::disk('analytics-local')->put('exports/expired.csv', "metric,value\n");
        ReportExport::query()->create([
            'workspace_id' => $analyticsWorkspace->getKey(), 'site_id' => $site->getKey(),
            'requested_by' => $analyticsWorkspace->users()->value('users.id'), 'token_hash' => hash('sha256', 'private'),
            'filters' => ['days' => 30], 'status' => 'completed', 'file_path' => 'exports/expired.csv',
            'created_at' => now()->subDays(5), 'updated_at' => now(), 'expires_at' => now()->subMinute(),
            'completed_at' => now()->subDays(4),
        ]);

        $summary = app(AnalyticsWorkspaceDataAdministrationProvider::class)->snapshot($platformUser, $coreWorkspace)->reports[0];
        $this->assertFalse($summary->downloadAvailable);
    }

    /** @return array{0: PlatformUser, 1: CoreWorkspace, 2: AnalyticsWorkspace, 3: Site, 4: string} */
    private function mappedOwnerFixture(): array
    {
        config(['platform.products.analytics.auth_authority' => 'core']);
        Queue::fake();
        $platformUser = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Analytics owner', 'email' => 'analytics-owner@example.test',
            'email_normalized' => 'analytics-owner@example.test', 'password' => 'hashed-password', 'status' => 'active',
        ]);
        $coreWorkspace = CoreWorkspace::query()->forceCreate([
            'id' => (string) Str::ulid(), 'owner_user_id' => $platformUser->getKey(),
            'name' => 'Shared Analytics workspace', 'slug' => 'shared-analytics-workspace', 'status' => 'active',
        ]);
        $membershipId = (string) Str::ulid();
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId, 'workspace_id' => $coreWorkspace->getKey(), 'user_id' => $platformUser->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $grantId = (string) Str::ulid();
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => $grantId, 'membership_id' => $membershipId, 'product' => 'analytics', 'role' => 'owner',
            'status' => 'active', 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productUser = AnalyticsUser::query()->forceCreate([
            'name' => $platformUser->name, 'email' => 'analytics-native@example.test', 'password' => 'hashed-password',
            'platform_user_id' => $platformUser->getKey(),
        ]);
        $analyticsWorkspace = AnalyticsWorkspace::create(['name' => 'Native Analytics workspace']);
        $analyticsWorkspace->users()->attach($productUser, ['role' => WorkspaceRole::Owner->value]);
        $site = $analyticsWorkspace->sites()->create([
            'name' => 'Mapped site', 'domains' => ['example.test'], 'timezone' => 'UTC', 'verified_at' => now(),
        ]);
        foreach ([
            ['user', (string) $productUser->getKey(), 'user', (string) $platformUser->getKey()],
            ['workspace', (string) $analyticsWorkspace->getKey(), 'workspace', (string) $coreWorkspace->getKey()],
        ] as [$sourceEntity, $sourceId, $canonicalEntity, $canonicalId]) {
            DB::connection('core')->table('legacy_identity_maps')->insert([
                'id' => (string) Str::ulid(), 'source_product' => 'analytics', 'source_entity' => $sourceEntity,
                'source_id' => $sourceId, 'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId,
                'status' => 'reconciled', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$platformUser, $coreWorkspace, $analyticsWorkspace, $site, $grantId];
    }
}
