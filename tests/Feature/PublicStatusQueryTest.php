<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicStatusQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_components_aggregate_health_in_one_query_with_correct_window_and_order(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $server = $owner->servers()->create(['name' => 'Status host', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $page = $owner->currentOrganization->statusPages()->create([
            'name' => 'Services', 'slug' => 'services', 'is_published' => true, 'created_by' => $owner->id,
        ]);

        for ($index = 0; $index < 6; $index++) {
            $website = $owner->websites()->create([
                'server_id' => $server->id,
                'name' => "service-{$index}.example.test",
                'description' => 'Public status component',
                'environment' => '',
                'url' => "service-{$index}.example.test",
                'provisioning_status' => Website::STATUS_ACTIVE,
                'health_check_enabled' => true,
                'health_status' => $index === 0 ? Website::HEALTH_UNHEALTHY : Website::HEALTH_HEALTHY,
                'health_last_checked_at' => now(),
            ]);
            $page->websites()->attach($website, ['display_name' => "Public {$index}", 'position' => $index]);
            if ($index === 5) {
                continue;
            }
            foreach ([[true, now()], [false, now()->subDays(30)], [true, now()->subDays(30)->subSecond()]] as [$success, $checkedAt]) {
                $website->healthChecks()->create([
                    'successful' => $success,
                    'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
                    'endpoint' => 'https://example.test/',
                    'checked_at' => $checkedAt,
                ]);
            }
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->getJson(route('status.report', $page->slug));
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'website_health_checks'));
        DB::disableQueryLog();

        $response->assertOk()->assertJsonCount(6, 'components')
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('components.0.name', 'Public 0')
            ->assertJsonPath('components.0.operational', false)
            ->assertJsonPath('components.0.uptime_30d', 50)
            ->assertJsonPath('components.4.operational', true)
            ->assertJsonPath('components.4.uptime_30d', 50)
            ->assertJsonPath('components.5.uptime_30d', null);
        $this->assertCount(1, $queries, 'Health aggregation must not issue queries per status component.');

        $page->update(['is_published' => false]);
        $this->getJson(route('status.report', $page->slug))->assertNotFound();
        $this->get(route('status.show', $page->slug))->assertNotFound();
    }
}
