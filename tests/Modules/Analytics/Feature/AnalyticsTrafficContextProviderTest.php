<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Data\Projects\ProjectTrafficWindowSummary;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\Core\AnalyticsProjectLink;
use App\Modules\Analytics\Services\Core\AnalyticsTrafficContextProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class AnalyticsTrafficContextProviderTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_it_returns_only_processed_aggregates_for_authorized_sites_and_bounded_windows(): void
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
            'last_processed_at' => '2026-04-02 12:01:00',
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
        $siteResource = ProjectResource::query()->create([
            'project_id' => $project->getKey(),
            'product' => 'analytics',
            'resource_type' => 'site',
            'resource_id' => (string) $site->getKey(),
            'name' => 'Storefront site',
            'status' => 'active',
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
        $privateSiteResource = ProjectResource::query()->create([
            'project_id' => $project->getKey(),
            'product' => 'analytics',
            'resource_type' => 'site',
            'resource_id' => (string) $privateSite->getKey(),
            'name' => 'Private site',
            'status' => 'active',
        ]);

        $incidentOpenedAt = CarbonImmutable::parse('2026-04-02 12:00:00', 'UTC');
        $this->recordPageview($site, '2026-04-02 11:10:00', 'visitor-a', processed: true);
        $this->recordPageview($site, '2026-04-02 11:30:00', 'visitor-a', processed: true);
        $convertedEvent = $this->recordPageview($site, '2026-04-02 11:55:00', 'visitor-b', processed: true);
        $this->recordPageview($site, '2026-04-02 11:56:00', 'visitor-event', processed: true, type: 'custom');
        $pendingEvent = $this->recordPageview($site, '2026-04-02 11:59:00', 'visitor-pending', processed: false);
        $this->recordPageview($site, '2026-04-02 12:00:00', 'visitor-boundary', processed: true);
        $this->recordPageview($site, '2026-04-02 10:30:00', 'visitor-prior', processed: true);
        $this->recordPageview($site, '2026-04-02 10:59:00', 'visitor-prior', processed: true);
        $this->recordPageview($site, '2026-04-02 09:59:00', 'visitor-outside', processed: true);
        $this->recordPageview($privateSite, '2026-04-02 11:15:00', 'private-visitor', processed: true);

        $goal = $site->goals()->create(['name' => 'Checkout', 'kind' => 'path', 'match_type' => 'exact', 'match_value' => '/checkout', 'active' => true]);
        $visit = $site->visits()->create([
            'visit_key' => 'converted-visit',
            'visitor_hash' => hash('sha256', 'visitor-converted'),
            'started_at' => '2026-04-02 11:54:00',
            'last_seen_at' => '2026-04-02 11:55:00',
            'pageviews' => 2,
            'conversion_count' => 1,
        ]);
        $site->goalConversions()->create([
            'goal_id' => $goal->getKey(),
            'goal_version_id' => $goal->versions()->firstOrFail()->getKey(),
            'analytics_event_id' => $convertedEvent->getKey(),
            'visit_id' => $visit->getKey(),
            'converted_at' => '2026-04-02 11:55:00',
        ]);
        $site->goalConversions()->create([
            'goal_id' => $goal->getKey(),
            'goal_version_id' => $goal->versions()->firstOrFail()->getKey(),
            'analytics_event_id' => $pendingEvent->getKey(),
            'converted_at' => '2026-04-02 11:59:00',
        ]);

        $provider = new AnalyticsTrafficContextProvider(new AnalyticsProjectLink(app(LegacyIdentityResolver::class)));
        $current = $provider->aggregate(
            $platformUser,
            $project,
            $siteResource,
            $incidentOpenedAt->subMinutes(60),
            $incidentOpenedAt,
        );
        $previous = $provider->aggregate(
            $platformUser,
            $project,
            $siteResource,
            $incidentOpenedAt->subMinutes(120),
            $incidentOpenedAt->subMinutes(60),
        );

        $this->assertInstanceOf(ProjectTrafficWindowSummary::class, $current);
        $this->assertSame(3, $current->pageviews);
        $this->assertSame(2, $current->visitors);
        $this->assertSame(1, $current->conversions);
        $this->assertSame(1, $current->convertedVisits);
        $this->assertSame('2026-04-02 12:01:00', $current->processedAt?->format('Y-m-d H:i:s'));
        $this->assertSame(2, $previous?->pageviews);
        $this->assertSame(1, $previous?->visitors);
        $this->assertSame(['pageviews', 'visitors', 'conversions', 'convertedVisits', 'processedAt', 'sourceUrl'], array_keys(get_object_vars($current)));
        $this->assertNull($provider->aggregate(
            $platformUser,
            $project,
            $privateSiteResource,
            $incidentOpenedAt->subMinutes(60),
            $incidentOpenedAt,
        ));
    }

    private function recordPageview(
        Site $site,
        string $occurredAt,
        string $visitor,
        bool $processed,
        string $type = 'pageview',
    ): AnalyticsEvent {
        $batch = $site->ingestionBatches()->create([
            'batch_id' => (string) Str::uuid(),
            'event_count' => 1,
            'status' => $processed ? 'processed' : 'pending',
            'accepted_at' => $occurredAt,
            'processed_at' => $processed ? $occurredAt : null,
        ]);

        return $site->events()->create([
            'ingestion_batch_id' => $batch->getKey(),
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'path' => '/',
            'visitor_hash' => hash('sha256', $visitor),
        ]);
    }
}
