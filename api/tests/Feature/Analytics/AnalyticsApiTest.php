<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\ApiScope;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that a token with the Analytics read scope lists the account's sites and reads a report as numbers, with
     * filters, and can't reach other accounts' sites.
     *
     * @return void
     */
    public function test_a_token_reads_sites_and_reports(): void
    {
        // Midday, so "an hour ago" is still today whenever the suite runs.
        $this->travelTo(now()->setTime(12, 0));
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $project = Project::factory()->for($account)->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['name' => 'Shop', 'domains' => ['shop.example'], 'timezone' => 'UTC']);
        foreach (['/pricing', '/pricing', '/'] as $path) {
            AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subHour(), 'received_at' => now(), 'path' => $path, 'visitor_hash' => 'v', 'device_category' => 'Desktop']);
        }
        $other = AnalyticsSite::factory()->for(Project::factory()->for(Account::factory())->withServices(['analytics']))->create();
        $token = fn (array $scopes): string => app(CreateApiToken::class)->handle($owner, $account, new CreateApiTokenData('test', array_values($scopes), 30))->plainText;
        $read = $token([ApiScope::AnalyticsRead]);

        $this->withToken($token([ApiScope::DeployRead]))->getJson('/api/v1/analytics/sites')->assertForbidden();
        // Each call is a fresh API request: forget whoever the previous one authenticated.
        $this->app['auth']->forgetGuards();
        $this->withToken($read)->getJson('/api/v1/analytics/sites')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $site->id)->assertJsonPath('data.0.name', 'Shop')->assertJsonPath('data.0.domains', ['shop.example']);

        $this->withToken($read)->getJson("/api/v1/analytics/sites/{$site->id}/report?days=7")->assertOk()
            ->assertJsonPath('data.metrics.pageviews.value', 3)
            ->assertJsonPath('data.metrics.visitors.value', 1)
            ->assertJsonPath('data.period.days', 7)
            ->assertJsonPath('data.series.granularity', 'day')
            ->assertJsonCount(7, 'data.series.points')
            ->assertJsonPath('data.pages.0', ['label' => '/pricing', 'value' => 2]);
        $this->withToken($read)->getJson("/api/v1/analytics/sites/{$site->id}/report?days=1&path=/pricing")->assertOk()
            ->assertJsonPath('data.metrics.pageviews.value', 2)->assertJsonPath('data.filters', ['path' => '/pricing'])->assertJsonPath('data.series.granularity', 'hour');
        $this->withToken($read)->getJson("/api/v1/analytics/sites/{$other->id}/report")->assertNotFound();

        app(RebuildSiteReports::class)->handle($site);
        $today = now($site->timezone)->toDateString();
        $this->withToken($read)->getJson("/api/v1/analytics/sites/{$site->id}/rows?from={$today}&to={$today}&dimension=path")->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.next_page', null)->assertJsonPath('meta.dimension', 'path')
            ->assertJsonPath('data.1', ['date' => $today, 'value' => '/pricing', 'pageviews' => 2, 'visits' => 1, 'visitors' => 1, 'conversions' => 0, 'converted_visits' => 0, 'bounces' => 0, 'bounce_eligible' => 1]);
        $this->withToken($read)->getJson("/api/v1/analytics/sites/{$site->id}/rows?from={$today}&to={$today}")->assertOk()->assertJsonPath('data.0.pageviews', 3)->assertJsonPath('data.0.value', null);
        $this->withToken($read)->getJson("/api/v1/analytics/sites/{$site->id}/rows?from=2025-01-01&to={$today}")->assertStatus(422);
        $this->withToken($read)->getJson("/api/v1/analytics/sites/{$site->id}/rows?from={$today}&to={$today}&dimension=secret")->assertUnprocessable();
        $this->withToken($read)->getJson("/api/v1/analytics/sites/{$other->id}/rows?from={$today}&to={$today}")->assertNotFound();
        $this->assertIsArray(json_decode((string) file_get_contents(public_path('connectors/looker-studio/appsscript.json')), true), 'The connector manifest is valid JSON.');
        $this->assertStringContainsString('/rows?from=', (string) file_get_contents(public_path('connectors/looker-studio/Code.gs')));
    }

    /**
     * Check a server sends pageviews and events with the visitor's address and browser, which set the device and
     * location, while bots, ignored addresses and tokens without the write scope are refused or dropped.
     *
     * @return void
     */
    public function test_a_server_sends_events(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $project = Project::factory()->for($account)->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['timezone' => 'UTC', 'verified_at' => now(), 'excluded_ips' => ['192.0.2.0/24'], 'custom_properties' => ['plan']]);
        $token = fn (array $scopes): string => app(CreateApiToken::class)->handle($owner, $account, new CreateApiTokenData('server', array_values($scopes), 30))->plainText;
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
        $url = "/api/v1/analytics/sites/{$site->id}/events";

        $this->withToken($token([ApiScope::AnalyticsRead]))->postJson($url, ['events' => [['type' => 'pageview', 'path' => '/']]])->assertForbidden();
        $write = $token([ApiScope::AnalyticsWrite]);
        $this->app['auth']->forgetGuards();
        $this->withToken($write)->postJson($url, ['events' => [
            ['type' => 'pageview', 'path' => '/pricing?x=1', 'ip' => '198.51.100.4', 'user_agent' => $iphone, 'referrer' => 'https://news.example/post', 'utm_source' => 'newsletter'],
            ['type' => 'event', 'name' => 'signup', 'path' => '/signup', 'ip' => '198.51.100.4', 'user_agent' => $iphone, 'properties' => ['plan' => 'pro', 'secret' => 'x']],
            ['type' => 'pageview', 'path' => '/', 'ip' => '192.0.2.10', 'user_agent' => $iphone],
            ['type' => 'pageview', 'path' => '/', 'user_agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'],
        ]])->assertStatus(202)->assertJsonPath('data.accepted', 2)->assertJsonPath('data.skipped', 2);
        $this->withToken($write)->postJson($url, ['events' => [['type' => 'event', 'path' => '/']]])->assertUnprocessable();

        $events = AnalyticsEvent::query()->orderBy('id')->get();
        $this->assertSame(['/pricing', '/signup'], $events->pluck('path')->all());
        [$pageview, $signup] = [$events->where('path', '/pricing')->firstOrFail(), $events->where('path', '/signup')->firstOrFail()];
        $this->assertSame(['Mobile', 'Safari', '17.4', 'iOS', '17.4', 'news.example', 'newsletter'], [$pageview->device_category, $pageview->browser, $pageview->browser_version, $pageview->operating_system, $pageview->os_version, $pageview->referrer_host, $pageview->utm_source]);
        $this->assertSame(['name' => 'signup', 'props' => ['plan' => 'pro']], $signup->properties);
        $this->assertSame($pageview->visitor_hash, $signup->visitor_hash, 'Same address and browser, same visitor.');
    }
}
