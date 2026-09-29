<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use App\Models\Project;
use App\Models\User;
use App\Support\CampaignLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CampaignsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that the builder tags links (keeping other parameters and replacing old UTM ones) and that campaign
     * results rank tagged visits with their conversions.
     *
     * @return void
     */
    public function test_campaign_links_are_built_and_campaigns_ranked(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['name' => 'Shop', 'domains' => ['shop.example.com']]);
        $visit = function (string $campaign, int $conversions) use ($site): void {
            (new AnalyticsVisit)->forceFill(['site_id' => $site->id, 'visit_key' => uniqid('v', true), 'started_at' => now()->subDay(), 'last_seen_at' => now()->subDay(),
                'entry_utm_source' => 'newsletter', 'entry_utm_medium' => 'email', 'entry_utm_campaign' => $campaign, 'pageviews' => 3, 'conversion_count' => $conversions])->save();
        };
        $visit('autumn', 1);
        $visit('autumn', 0);
        $visit('spring', 0);

        $base = "/projects/{$project->id}/analytics/campaigns";
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Campaign link builder')->assertSee('https://shop.example.com/')
            ->assertSeeInOrder(['autumn', 'newsletter / email', '2', '6', '1', '(50%)', 'spring']);
        $this->actingAs($owner)->get($base.'?'.http_build_query(['url' => 'https://shop.example.com/sale?ref=x&utm_source=old#top', 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn launch']))
            ->assertOk()->assertSee('https://shop.example.com/sale?ref=x&amp;utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=autumn%20launch#top', false);
        $this->actingAs($owner)->get($base.'?url=javascript:alert(1)')->assertOk()->assertSee('Enter a full http:// or https:// address.');

        $this->assertNull(CampaignLink::build('ftp://x.test/', []));
        $this->assertSame('https://x.test/', CampaignLink::build('https://x.test', ['utm_source' => ' ']));
    }
}
