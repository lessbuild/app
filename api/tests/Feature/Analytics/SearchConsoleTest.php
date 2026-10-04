<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Contracts\Analytics\SearchConsole;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Services\Analytics\GoogleSearchConsole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeSearchConsole;
use Tests\TestCase;

final class SearchConsoleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check connecting a site to Search Console through Google's redirect, the automatic property match, choosing
     * another property, the search terms in the report, and disconnecting.
     *
     * @return void
     */
    public function test_a_site_is_connected_and_shows_search_terms(): void
    {
        $fake = new FakeSearchConsole;
        $this->app->instance(SearchConsole::class, $fake);
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['domains' => ['shop.example']]);
        $viewer = User::factory()->create();
        $project->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $sitePage = "/api/app/projects/{$project->id}/analytics/sites/{$site->id}";

        $this->actingAs($viewer)->postJson("{$sitePage}/search-console/connect")->assertForbidden();
        $this->actingAs($owner)->postJson("{$sitePage}/search-console/connect")->assertJsonRedirect('https://accounts.google.test/auth?state='.session('search-console.connect.state'));
        $this->get('/analytics/search-console/callback?state=wrong&code=good-code')->assertForbidden();

        $this->actingAs($owner)->postJson("{$sitePage}/search-console/connect");
        $state = (string) session('search-console.connect.state');
        $this->get("/analytics/search-console/callback?state={$state}&code=good-code")->assertRedirectContains("/projects/{$project->id}/analytics/sites/{$site->id}?notice=");
        $site->refresh();
        $this->assertSame(['refresh-token', 'sc-domain:shop.example'], [$site->search_console_token, $site->search_console_property], 'The domain property matching the site is picked.');
        $this->assertNotSame('refresh-token', $site->getRawOriginal('search_console_token'), 'The token is stored encrypted.');

        $this->actingAs($owner)->putJson("{$sitePage}/search-console", ['search_console_property' => 'https://elsewhere.example/'])->assertJsonValidationErrors('search_console_property');
        $this->actingAs($owner)->putJson("{$sitePage}/search-console", ['search_console_property' => 'https://blog.example/'])->assertJsonRedirect($sitePage);
        $this->actingAs($owner)->getJson($sitePage)->assertOk()->assertJsonHasText('https://blog.example/')->assertJsonPath('searchConsole.connected', true);

        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/analytics?days=7&path=/shoes")->assertOk()
            ->assertJsonHasText('buy shoes online')->assertJsonPath('report.searchTerms.rows.0.ctr', fn ($ctr): bool => (float) $ctr === 4.7);
        $this->assertSame(['https://blog.example/', now($site->timezone)->subDays(6)->toDateString(), now($site->timezone)->toDateString(), '/shoes'], $fake->asked[0]);

        $this->actingAs($owner)->postJson("{$sitePage}/search-console/connect");
        $state = (string) session('search-console.connect.state');
        $this->get("/analytics/search-console/callback?state={$state}&error=access_denied")->assertRedirectContains("/projects/{$project->id}/analytics/sites/{$site->id}?error=");

        $this->actingAs($owner)->deleteJson("{$sitePage}/search-console")->assertJsonRedirect($sitePage);
        $this->assertNull($site->refresh()->search_console_token);
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/analytics?days=7")->assertOk()->assertJsonPath('report.searchTerms', null);
    }

    /**
     * Check the Google client: the consent URL, swapping the code, listing properties and reading search terms with
     * a fresh access token.
     *
     * @return void
     */
    public function test_the_google_client_speaks_the_search_console_api(): void
    {
        config(['services.google_search_console.client_id' => 'client-id', 'services.google_search_console.client_secret' => 'client-secret']);
        Http::fake([
            'oauth2.googleapis.com/token' => fn (Request $request) => Http::response($request['grant_type'] === 'authorization_code'
                ? ['refresh_token' => 'refresh-1', 'access_token' => 'access-1']
                : ['access_token' => 'access-2']),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
                ['siteUrl' => 'sc-domain:shop.example', 'permissionLevel' => 'siteOwner'],
                ['siteUrl' => 'https://unverified.example/', 'permissionLevel' => 'siteUnverifiedUser'],
            ]]),
            'www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => [['keys' => ['shoes'], 'clicks' => 3, 'impressions' => 40, 'ctr' => 0.075, 'position' => 4.44]]]),
        ]);
        $google = new GoogleSearchConsole;

        $this->assertTrue($google->configured());
        $url = $google->authorizationUrl('state-1');
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/webmasters.readonly'), $url);
        $this->assertSame('refresh-1', $google->exchange('code-1'));
        $this->assertSame(['sc-domain:shop.example'], $google->properties('refresh-1'));
        $this->assertSame([['query' => 'shoes', 'clicks' => 3, 'impressions' => 40, 'ctr' => 7.5, 'position' => 4.4]],
            $google->topQueries('refresh-1', 'sc-domain:shop.example', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-07'), '/shoes'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), rawurlencode('sc-domain:shop.example').'/searchAnalytics/query')
            && $request->hasHeader('Authorization', 'Bearer access-2') && $request['dimensionFilterGroups'][0]['filters'][0]['expression'] === '/shoes');
    }
}
