<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

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
    }
}
