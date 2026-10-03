<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AutomaticEventsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that outbound links, downloads and missing pages are stored with only their link or file, ranked in the
     * report and listed on the page, and that the site page explains how to switch them on.
     *
     * @return void
     */
    public function test_outbound_links_downloads_and_missing_pages_are_counted(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()]);
        $event = fn (string $path, array $properties): array => ['id' => (string) Str::uuid(), 'type' => 'event', 'path' => $path, 'visitor' => 'v', 'properties' => $properties];

        $this->withHeader('Origin', 'https://example.com')->postJson("/api/v1/collect/{$site->public_id}", ['events' => [
            $event('/', ['name' => 'outbound_link', 'url' => "github.com/acme?token=secret\n", 'email' => 'leak@example.com']),
            $event('/', ['name' => 'outbound_link', 'url' => 'github.com/acme']),
            $event('/docs', ['name' => 'file_download', 'file' => '/files/guide.pdf#page=2']),
            $event('/old-page', ['name' => 'not_found', 'url' => 'ignored.example']),
            $event('/', ['name' => 'signup', 'url' => 'not-kept.example']),
        ]])->assertAccepted()->assertJsonPath('accepted', 5);

        $stored = AnalyticsEvent::query()->orderBy('id')->pluck('properties')->all();
        $this->assertSame(['name' => 'outbound_link', 'url' => 'github.com/acme'], $stored[0], 'Query strings and anything else are dropped.');
        $this->assertSame(['name' => 'file_download', 'file' => '/files/guide.pdf'], $stored[2]);
        $this->assertSame([['name' => 'not_found'], ['name' => 'signup']], [$stored[3], $stored[4]]);

        AnalyticsEvent::query()->update(['ingestion_batch_id' => null]);
        app(RebuildSiteReports::class)->handle($site);
        $report = app(AnalyticsReportQuery::class)->handle($site, 7);
        $this->assertSame([['label' => 'github.com/acme', 'value' => 2]], $report['outboundLinks']);
        $this->assertSame([['label' => '/files/guide.pdf', 'value' => 1]], $report['fileDownloads']);
        $this->assertSame([['label' => '/old-page', 'value' => 1]], $report['notFound']);

        $this->actingAs($owner)->get("/projects/{$project->id}/analytics?days=7")->assertOk()
            ->assertSee(__('Outbound links'))->assertSee('github.com/acme')->assertSee(__('Pages not found'))->assertSee('/old-page');
        $this->actingAs($owner)->get("/projects/{$project->id}/analytics/sites/{$site->id}")->assertOk()->assertSee('data-outbound data-downloads data-vitals', false);
    }
}
