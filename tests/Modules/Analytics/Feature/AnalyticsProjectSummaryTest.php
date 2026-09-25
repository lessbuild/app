<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Analytics\Models\ReportDailyAggregate;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\Core\AnalyticsProjectLink;
use App\Modules\Analytics\Services\Core\AnalyticsProjectSetup;
use App\Modules\Analytics\Services\Core\AnalyticsProjectSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class AnalyticsProjectSummaryTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_summary_counts_seven_local_days_for_only_accessible_project_sites(): void
    {
        $platformUser = PlatformUser::query()->create([
            'name' => 'Casey Owner',
            'email' => 'casey@example.test',
            'email_normalized' => 'casey@example.test',
            'status' => 'active',
        ]);
        $coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $platformUser->getKey(),
            'name' => 'Core workspace',
            'slug' => 'core-workspace',
            'status' => 'active',
        ]);
        $project = Project::query()->create([
            'workspace_id' => $coreWorkspace->getKey(),
            'created_by_user_id' => $platformUser->getKey(),
            'name' => 'Storefront',
            'slug' => 'storefront',
            'status' => 'active',
        ]);

        $analyticsUser = AnalyticsUser::query()->create([
            'name' => 'Casey Owner',
            'email' => 'casey@example.test',
            'password' => 'not-used-in-this-test',
        ]);
        $analyticsWorkspace = AnalyticsWorkspace::query()->create(['name' => 'Storefront analytics']);
        $analyticsWorkspace->users()->attach($analyticsUser, ['role' => 'owner']);
        $site = $analyticsWorkspace->sites()->create([
            'name' => 'Storefront site',
            'domains' => ['storefront.example.test'],
            'timezone' => 'UTC',
        ]);
        $secondSite = $analyticsWorkspace->sites()->create([
            'name' => 'Storefront staging site',
            'domains' => ['staging.storefront.example.test'],
            'timezone' => 'UTC',
        ]);

        $outsider = AnalyticsUser::query()->create([
            'name' => 'Different owner',
            'email' => 'different@example.test',
            'password' => 'not-used-in-this-test',
        ]);
        $outsiderWorkspace = AnalyticsWorkspace::query()->create(['name' => 'Private analytics']);
        $outsiderWorkspace->users()->attach($outsider, ['role' => 'owner']);
        $privateSite = $outsiderWorkspace->sites()->create([
            'name' => 'Private site',
            'domains' => ['private.example.test'],
            'timezone' => 'UTC',
        ]);

        LegacyIdentityMap::query()->create([
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => (string) $analyticsUser->getKey(),
            'canonical_entity' => 'user',
            'canonical_id' => $platformUser->getKey(),
            'status' => 'reconciled',
        ]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'analytics',
            'source_entity' => 'workspace',
            'source_id' => (string) $analyticsWorkspace->getKey(),
            'canonical_entity' => 'workspace',
            'canonical_id' => $coreWorkspace->getKey(),
            'status' => 'reconciled',
        ]);
        ProjectResource::query()->create([
            'project_id' => $project->getKey(),
            'product' => 'analytics',
            'resource_type' => 'site',
            'resource_id' => (string) $site->getKey(),
            'name' => 'Storefront site',
            'status' => 'active',
        ]);
        ProjectResource::query()->create([
            'project_id' => $project->getKey(),
            'product' => 'analytics',
            'resource_type' => 'site',
            'resource_id' => (string) $secondSite->getKey(),
            'name' => 'Storefront staging site',
            'status' => 'active',
        ]);
        ProjectResource::query()->create([
            'project_id' => $project->getKey(),
            'product' => 'analytics',
            'resource_type' => 'site',
            'resource_id' => (string) $privateSite->getKey(),
            'name' => 'Private site',
            'status' => 'active',
        ]);

        $privateSite->events()->create([
            'event_id' => '00000000-0000-4000-8000-000000000071',
            'type' => 'pageview',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/private',
        ]);
        Route::get('/analytics/sites/{site}/setup', static fn () => null)->name('analytics.sites.setup');
        Route::get('/analytics/dashboard/{site?}', static fn () => null)->name('analytics.dashboard');
        Route::getRoutes()->refreshNameLookups();

        $setupProvider = new AnalyticsProjectSetup(app(AnalyticsProjectLink::class));
        $pendingSteps = collect($setupProvider->steps($platformUser, $project));
        $this->assertCount(4, $pendingSteps);
        $firstSiteVerification = $pendingSteps->firstWhere('id', 'analytics.site.'.$site->getKey());
        $firstSiteEvent = $pendingSteps->firstWhere('id', 'analytics.event.'.$site->getKey());
        $this->assertSame(ProjectSetupStepState::NeedsAction, $firstSiteVerification->state);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $firstSiteEvent->state);
        $this->assertSame('Site', $firstSiteEvent->contextLabel);
        $this->assertSame('Storefront site', $firstSiteEvent->contextName);
        $this->assertSame(route('analytics.sites.setup', $site), $firstSiteEvent->url);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $pendingSteps->firstWhere('id', 'analytics.site.'.$secondSite->getKey())->state);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $pendingSteps->firstWhere('id', 'analytics.event.'.$secondSite->getKey())->state);
        $this->assertFalse($pendingSteps->contains(fn ($step): bool => $step->contextName === $privateSite->name));

        $site->update(['verified_at' => now()]);
        $site->events()->create([
            'event_id' => '00000000-0000-4000-8000-000000000072',
            'type' => 'pageview',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/',
        ]);
        $completeSteps = collect($setupProvider->steps($platformUser, $project));
        $this->assertSame(ProjectSetupStepState::Complete, $completeSteps->firstWhere('id', 'analytics.site.'.$site->getKey())->state);
        $this->assertSame(ProjectSetupStepState::Complete, $completeSteps->firstWhere('id', 'analytics.event.'.$site->getKey())->state);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $completeSteps->firstWhere('id', 'analytics.site.'.$secondSite->getKey())->state);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $completeSteps->firstWhere('id', 'analytics.event.'.$secondSite->getKey())->state);

        $today = CarbonImmutable::now('UTC');
        $this->aggregate($site, $today, 42, 12);
        $this->aggregate($site, $today->subDays(6), 58, 18);
        $this->aggregate($site, $today->subDays(7), 900, 900);
        $this->aggregate($privateSite, $today, 5000, 4000);

        $summary = (new AnalyticsProjectSummary(app(AnalyticsProjectLink::class)))
            ->summarize($platformUser, $project);

        $this->assertNotNull($summary);
        $this->assertSame('100 pageviews · 30 visits in the last 7 days', $summary->detail);

        $environment = $project->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'environment_type' => 'production',
            'status' => 'active',
        ]);
        $summaryProvider = new AnalyticsProjectSummary(app(AnalyticsProjectLink::class));
        $unmappedSummary = $summaryProvider
            ->summarizeForEnvironment($platformUser, $project, $environment);
        $unmappedSteps = $setupProvider->stepsForEnvironment($platformUser, $project, $environment);

        $this->assertSame(ProjectProductSnapshotState::Unavailable, $unmappedSummary?->state);
        $this->assertStringContainsString('No authorized Analytics site is mapped', $unmappedSummary?->detail);
        $this->assertCount(1, $unmappedSteps);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $unmappedSteps[0]->state);
        $this->assertSame('Production', $unmappedSteps[0]->contextName);

        ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('product', 'analytics')
            ->where('resource_type', 'site')
            ->where('resource_id', (string) $site->getKey())
            ->update(['environment_id' => $environment->getKey()]);
        $this->aggregate($secondSite, CarbonImmutable::now('UTC'), 9999, 9999);
        $environmentSummary = $summaryProvider->summarizeForEnvironment($platformUser, $project, $environment);
        $environmentSteps = collect($setupProvider->stepsForEnvironment($platformUser, $project, $environment));

        $this->assertSame(ProjectProductSnapshotState::Current, $environmentSummary?->state);
        $this->assertSame('100 pageviews · 30 visits in the last 7 days', $environmentSummary?->detail);
        $this->assertSame('Analytics traffic · Production · 7 days', $environmentSummary?->title);
        $this->assertCount(2, $environmentSteps);
        $this->assertTrue($environmentSteps->every(fn ($step): bool => $step->contextName === $site->name));
        $this->assertFalse($environmentSteps->contains(fn ($step): bool => $step->contextName === $secondSite->name));
    }

    private function aggregate(Site $site, CarbonImmutable $date, int $pageviews, int $visits): void
    {
        ReportDailyAggregate::query()->create([
            'site_id' => $site->getKey(),
            'local_date' => $date->toDateString(),
            'dimension' => 'all',
            'dimension_value' => null,
            'pageviews' => $pageviews,
            'visits' => $visits,
        ]);
    }
}
