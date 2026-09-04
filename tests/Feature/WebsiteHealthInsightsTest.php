<?php

namespace Tests\Feature;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteHealthInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_metrics_from_retained_observations(): void
    {
        [$owner, $website] = $this->infrastructure('Observed');
        $this->record($website, true, 101, now()->subMinutes(5));
        $this->record($website, true, null, now()->subMinutes(4));
        $this->record($website, true, 116, now()->subMinutes(3));
        $this->record($website, false, 500, now()->subMinutes(2));
        $this->record($website, false, 600, now()->subMinute());

        $this->actingAs($owner)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertViewHas('healthMetrics', [
                'total' => 5,
                'successful' => 3,
                'success_rate' => 60,
                'median_healthy_duration_ms' => 109,
                'failure_streak' => 2,
            ])
            ->assertSee('Retained checks')
            ->assertSee('Observed check success')
            ->assertSee('60%')
            ->assertSee('3 successful checks')
            ->assertSee('Median healthy response')
            ->assertSee('109 ms')
            ->assertSee('Failed and unreported timings are excluded.')
            ->assertSee('2 consecutive failed checks')
            ->assertSee('not an SLA uptime calculation.');
    }

    public function test_empty_history_has_explicit_unknown_metrics(): void
    {
        [$owner, $website] = $this->infrastructure('Empty');

        $this->actingAs($owner)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertViewHas('healthMetrics', [
                'total' => 0,
                'successful' => 0,
                'success_rate' => null,
                'median_healthy_duration_ms' => null,
                'failure_streak' => 0,
            ])
            ->assertSee('Not available')
            ->assertSee('Not recorded')
            ->assertSee('0 consecutive failed checks')
            ->assertSee('No health checks have been recorded yet.');
    }

    public function test_metrics_use_only_the_newest_hundred_checks(): void
    {
        [$owner, $website] = $this->infrastructure('Bounded');
        $this->record($website, true, 9999, now()->subMinutes(101));

        foreach (range(1, 100) as $position) {
            $this->record(
                $website,
                $position <= 98,
                $position <= 98 ? 100 : 800,
                now()->subMinutes(101 - $position),
            );
        }

        $this->actingAs($owner)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertViewHas('healthMetrics', [
                'total' => WebsiteHealthCheck::MAX_PER_WEBSITE,
                'successful' => 98,
                'success_rate' => 98,
                'median_healthy_duration_ms' => 100,
                'failure_streak' => 2,
            ]);
    }

    /** @return array{User, Website} */
    private function infrastructure(string $prefix): array
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create([
            'name' => "{$prefix} server",
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-root-secret',
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => "{$prefix} website",
            'url' => str($prefix)->lower().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'health_check_enabled' => true,
            'health_check_path' => '/health/ready',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $website];
    }

    private function record(Website $website, bool $successful, ?int $duration, mixed $checkedAt): void
    {
        $website->healthChecks()->create([
            'successful' => $successful,
            'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            'http_status' => $successful ? 200 : 503,
            'duration_ms' => $duration,
            'endpoint' => 'http://health-insights.example.com/health/ready',
            'error' => $successful ? null : 'Health check failed.',
            'checked_at' => $checkedAt,
        ]);
    }
}
