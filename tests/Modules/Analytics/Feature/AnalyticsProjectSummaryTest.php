<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\ReportDailyAggregate;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\Core\AnalyticsProjectLink;
use App\Modules\Analytics\Services\Core\AnalyticsProjectSetup;
use App\Modules\Analytics\Services\Core\AnalyticsProjectSummary;
use Carbon\CarbonImmutable;
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
        $setupProvider = new AnalyticsProjectSetup(new AnalyticsProjectLink(app(LegacyIdentityResolver::class)));
        $pendingSteps = $setupProvider->steps($platformUser, $project);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $pendingSteps[0]->state);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $pendingSteps[1]->state);

        $site->update(['verified_at' => now()]);
        $site->events()->create([
            'event_id' => '00000000-0000-4000-8000-000000000072',
            'type' => 'pageview',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/',
        ]);
        $completeSteps = $setupProvider->steps($platformUser, $project);
        $this->assertSame(ProjectSetupStepState::Complete, $completeSteps[0]->state);
        $this->assertSame(ProjectSetupStepState::Complete, $completeSteps[1]->state);

        $today = CarbonImmutable::now('UTC');
        $this->aggregate($site, $today, 42, 12);
        $this->aggregate($site, $today->subDays(6), 58, 18);
        $this->aggregate($site, $today->subDays(7), 900, 900);
        $this->aggregate($privateSite, $today, 5000, 4000);

        $summary = (new AnalyticsProjectSummary(new AnalyticsProjectLink(app(LegacyIdentityResolver::class))))
            ->summarize($platformUser, $project);

        $this->assertNotNull($summary);
        $this->assertSame('100 pageviews · 30 visits in the last 7 days', $summary->detail);
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
