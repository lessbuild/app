<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\FilteredVisitsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GroupsSearchSpamTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check content groups add up their pages and filter reports, site searches (and empty ones) are counted, and bots
     * and referrer spam are dropped and counted.
     *
     * @return void
     */
    public function test_groups_searches_and_spam(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()]);
        $this->actingAs($owner)->putJson("/api/app/projects/{$project->id}/analytics/sites/{$site->id}", ['name' => 'Shop', 'domains' => 'example.com', 'timezone' => 'UTC',
            'content_groups' => "Blog: /blog/*\nDocs: /docs/*", 'blocked_referrers' => 'https://Spammy.example/page'])->assertSuccessful();
        $this->assertSame([['name' => 'Blog', 'pattern' => '/blog/*'], ['name' => 'Docs', 'pattern' => '/docs/*']], $site->refresh()->content_groups);
        $this->assertSame(['spammy.example'], $site->blocked_referrers);

        $send = fn (array $event, string $agent = 'Mozilla/5.0') => $this->withHeaders(['Origin' => 'https://example.com', 'User-Agent' => $agent])
            ->postJson("/api/v1/collect/{$site->public_id}", ['events' => [['id' => (string) Str::uuid(), 'type' => 'pageview', 'path' => '/', ...$event]]]);
        foreach (['/blog/one', '/blog/two', '/docs/start', '/pricing'] as $path) {
            $send(['path' => $path])->assertAccepted();
        }
        $send(['path' => '/search?q=Blue+Shoes', 'search' => 'Blue Shoes'])->assertAccepted();
        $send(['path' => '/search', 'search' => 'me@example.com'])->assertAccepted();
        $send(['type' => 'event', 'path' => '/search', 'properties' => ['name' => 'search', 'term' => 'purple hats', 'results' => 0]])->assertAccepted();
        $send(['path' => '/', 'referrer_host' => 'semalt.com'])->assertAccepted()->assertJson(['accepted' => 0]);
        $send(['path' => '/', 'referrer_host' => 'www.spammy.example'])->assertAccepted()->assertJson(['accepted' => 0]);
        $send(['path' => '/'], 'Googlebot/2.1')->assertAccepted();
        app(RebuildSiteReports::class)->handle($site);

        $report = app(AnalyticsReportQuery::class)->handle($site, 7);
        $this->assertSame([['label' => 'Blog', 'value' => 2], ['label' => 'Docs', 'value' => 1]], $report['contentGroups']);
        $this->assertContains(['label' => 'blue shoes', 'value' => 1], $report['searches']);
        $this->assertContains(['label' => 'purple hats', 'value' => 1], $report['searches']);
        $this->assertNotContains('me@example.com', array_column($report['searches'], 'label'), 'Email addresses aren’t kept.');
        $this->assertSame([['label' => 'purple hats', 'value' => 1]], $report['emptySearches']);
        $this->assertSame('2', app(AnalyticsReportQuery::class)->handle($site, 7, ['group' => 'Blog'])['metrics'][0]['value']);
        $this->assertEqualsCanonicalizing(['spam' => 2, 'bot' => 1], app(FilteredVisitsQuery::class)->handle($site));
        $this->flushHeaders();
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/analytics/sites/{$site->id}")->assertJsonFragment(['label' => __('Referrer spam'), 'count' => 2]);
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/analytics?days=7")->assertJsonFragment(['key' => 'contentGroups', 'title' => __('Content groups'), 'empty' => null, 'filter' => 'group'])->assertJsonHasText('Blog');
    }
}
