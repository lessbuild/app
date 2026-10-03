<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AnalyticsAdAccount;
use App\Models\AnalyticsAdSpend;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AdPlatformsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check a site connects a Google Ads account through Google's sign-in, chooses the account, and its spend per
     * campaign and day arrives under the google source; reading again replaces the days, and disconnecting stops it.
     *
     * @return void
     */
    public function test_google_ads_spend_is_read_from_a_connected_account(): void
    {
        config(['services.google_search_console.client_id' => 'client-id', 'services.google_search_console.client_secret' => 'client-secret', 'services.google_ads.developer_token' => 'dev-token']);
        $cost = 12_340_000;
        Http::fake(function (Request $request) use (&$cost) {
            return match (true) {
                str_contains($request->url(), 'oauth2.googleapis.com/token') => Http::response($request['grant_type'] === 'authorization_code' ? ['refresh_token' => 'refresh-1'] : ['access_token' => 'access-1']),
                str_ends_with($request->url(), 'customers:listAccessibleCustomers') => Http::response(['resourceNames' => ['customers/1234567890', 'customers/9999999999']]),
                str_contains($request->url(), '/customers/9999999999/googleAds:search') => Http::response(['results' => [['customer' => ['descriptiveName' => 'Agency MCC', 'manager' => true]]]]),
                str_contains($request->url(), '/customers/1234567890/googleAds:searchStream') => Http::response([['results' => [
                    ['segments' => ['date' => now()->subDay()->toDateString()], 'campaign' => ['name' => 'spring_sale'], 'metrics' => ['costMicros' => (string) $cost, 'clicks' => '30', 'impressions' => '900'], 'customer' => ['currencyCode' => 'EUR']],
                ]]]),
                str_contains($request->url(), '/customers/1234567890/googleAds:search') => Http::response(['results' => [['customer' => ['descriptiveName' => 'Shop Ads', 'currencyCode' => 'EUR', 'manager' => false]]]]),
                default => Http::response([], 404),
            };
        });
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create();
        $viewer = User::factory()->create();
        $project->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $campaigns = "/projects/{$project->id}/analytics/campaigns?site={$site->id}";
        $sites = "/projects/{$project->id}/analytics/sites/{$site->id}";

        $this->actingAs($owner)->get($campaigns)->assertOk()->assertSee('Connect Google Ads')->assertDontSee('Connect Meta Ads');
        $this->actingAs($viewer)->post("{$sites}/ads/google/connect")->assertForbidden();
        $redirect = $this->actingAs($owner)->post("{$sites}/ads/google/connect")->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/adwords'), (string) $redirect);
        $state = (string) session('ads.connect.state');
        $this->get('/analytics/ads/callback/google?state=wrong&code=c')->assertForbidden();
        $this->actingAs($owner)->post("{$sites}/ads/google/connect");
        $state = (string) session('ads.connect.state');
        $this->get("/analytics/ads/callback/google?state={$state}&code=good")->assertRedirect("/projects/{$project->id}/analytics/campaigns?site={$site->id}");

        $this->actingAs($owner)->get($campaigns)->assertOk()->assertSee('Shop Ads · EUR')->assertDontSee('Agency MCC');
        $this->actingAs($owner)->post("{$sites}/ads", ['account_id' => '5555555555'])->assertSessionHasErrors('account_id');
        $this->actingAs($owner)->post("{$sites}/ads", ['account_id' => '1234567890'])->assertRedirect()->assertSessionHas('status');
        $account = AnalyticsAdAccount::query()->sole();
        $this->assertSame(['google', 'Shop Ads', 'refresh-1'], [$account->source, $account->name, $account->credential]);
        $spend = AnalyticsAdSpend::query()->sole();
        $this->assertSame(['spring_sale', 1234, 'EUR', 30], [$spend->campaign, $spend->cost_cents, $spend->currency, $spend->clicks]);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('developer-token', 'dev-token') && $request->hasHeader('Authorization', 'Bearer access-1'));

        $cost = 20_000_000;
        $this->actingAs($owner)->post("{$sites}/ads/{$account->id}/sync")->assertRedirect()->assertSessionHas('status');
        $this->assertSame(2000, AnalyticsAdSpend::query()->sole()->cost_cents);
        $this->assertSame(0, Artisan::call('analytics:sync-ad-spend'));
        $this->actingAs($owner)->get($campaigns)->assertOk()->assertSee('Shop Ads')->assertSee('filed under google');

        $this->actingAs($owner)->delete("{$sites}/ads/{$account->id}")->assertRedirect();
        $this->assertSame(0, AnalyticsAdAccount::query()->count());
        $this->assertSame(1, AnalyticsAdSpend::query()->count());
    }

    /**
     * Check Meta: the long-lived token, ad accounts, and daily campaign insights across pages filed under facebook,
     * with a failure recorded on the account.
     *
     * @return void
     */
    public function test_meta_ads_spend_is_read_across_pages(): void
    {
        config(['services.meta_ads.app_id' => 'app-id', 'services.meta_ads.app_secret' => 'app-secret']);
        $meta = new ArrayObject(['fail' => false]);
        Http::fake(function (Request $request) use ($meta) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'oauth/access_token') => Http::response(['access_token' => str_contains($url, 'fb_exchange_token') ? 'long-token' : 'short-token']),
                str_contains($url, '/me/adaccounts') => Http::response(['data' => [['account_id' => '111', 'name' => 'Shop Meta', 'currency' => 'USD']]]),
                $meta['fail'] === true && str_contains($url, '/act_111') => Http::response(['error' => ['message' => 'Session has expired']], 400),
                str_contains($url, '/act_111/insights') && str_contains($url, 'after=page2') => Http::response(['data' => [['date_start' => now()->subDays(2)->toDateString(), 'campaign_name' => 'retarget', 'spend' => '3.50', 'clicks' => '4', 'impressions' => '100']]]),
                str_contains($url, '/act_111/insights') => Http::response(['data' => [['date_start' => now()->subDay()->toDateString(), 'campaign_name' => 'retarget', 'spend' => '7.25', 'clicks' => '9', 'impressions' => '300']],
                    'paging' => ['next' => 'https://graph.facebook.com/v21.0/act_111/insights?after=page2&access_token=long-token']]),
                str_contains($url, '/act_111') => Http::response(['currency' => 'USD']),
                default => Http::response([], 404),
            };
        });
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create();
        $sites = "/projects/{$project->id}/analytics/sites/{$site->id}";

        $redirect = (string) $this->actingAs($owner)->post("{$sites}/ads/meta/connect")->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/v21.0/dialog/oauth?', $redirect);
        $this->assertStringContainsString('scope=ads_read', $redirect);
        $state = (string) session('ads.connect.state');
        $this->get("/analytics/ads/callback/meta?state={$state}&code=good")->assertRedirect();
        $this->actingAs($owner)->post("{$sites}/ads", ['account_id' => '111'])->assertRedirect();

        $this->assertSame('long-token', AnalyticsAdAccount::query()->sole()->credential);
        $this->assertSame([350, 725], AnalyticsAdSpend::query()->where('source', 'facebook')->orderBy('date')->pluck('cost_cents')->all());

        $meta['fail'] = true;
        $this->actingAs($owner)->post("{$sites}/ads/".AnalyticsAdAccount::query()->sole()->id.'/sync')->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('Session has expired', (string) AnalyticsAdAccount::query()->sole()->error);
    }
}
