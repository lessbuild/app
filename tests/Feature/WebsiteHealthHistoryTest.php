<?php

namespace Tests\Feature;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use App\Services\ManagedSsh;
use App\Services\Runner;
use App\Services\WebsiteHealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebsiteHealthHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_the_latest_twenty_checks_and_exports_safe_retained_history(): void
    {
        [$owner, $website] = $this->infrastructure('Owner');
        [, $foreignWebsite] = $this->infrastructure('Foreign');
        foreach (range(1, 21) as $position) {
            $website->healthChecks()->create([
                'successful' => $position % 2 === 0,
                'source' => $position % 2 === 0
                    ? WebsiteHealthCheck::SOURCE_AUTOMATIC
                    : WebsiteHealthCheck::SOURCE_MANUAL,
                'http_status' => $position % 2 === 0 ? 200 : 503,
                'duration_ms' => 100 + $position,
                'endpoint' => 'http://app.example.com/health/ready',
                'error' => $position === 1
                    ? 'Old check hidden from the page'
                    : ($position === 21 ? " =2+2\n<script>latest failure</script>" : null),
                'checked_at' => now()->subMinutes(22 - $position),
            ]);
        }
        $foreignWebsite->healthChecks()->create([
            'successful' => false,
            'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            'endpoint' => 'http://private.example.com/health',
            'error' => 'Private check result',
            'checked_at' => now(),
        ]);

        $page = $this->actingAs($owner)->get(route('websites.show', $website));
        $page
            ->assertSuccessful()
            ->assertSee('Recent health checks')
            ->assertSee('Manual')
            ->assertSee('Automatic')
            ->assertSee('HTTP 200')
            ->assertSee('HTTP 503')
            ->assertSee('121 ms')
            ->assertSee('<script>latest failure</script>')
            ->assertDontSee('<script>latest failure</script>', false)
            ->assertDontSee('Old check hidden from the page')
            ->assertDontSee('Private check result')
            ->assertSee(route('websites.health-checks.export', $website));
        $this->assertSame(20, substr_count($page->getContent(), '<tr class="align-top">'));

        $export = $this->get(route('websites.health-checks.export', $website));
        $export
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            'attachment; filename=lessbuild-website-'.$website->id.'-health-checks-',
            (string) $export->headers->get('content-disposition'),
        );
        $content = $export->streamedContent();
        $rows = $this->csvRows($content);
        $this->assertSame([
            'Check ID',
            'Result',
            'Source',
            'HTTP status',
            'Duration ms',
            'Endpoint',
            'Error',
            'Checked at',
        ], $rows[0]);
        $this->assertCount(22, $rows);
        $this->assertSame('failed', $rows[1][1]);
        $this->assertSame('manual', $rows[1][2]);
        $this->assertSame('503', $rows[1][3]);
        $this->assertSame('121', $rows[1][4]);
        $this->assertSame("' =2+2\n<script>latest failure</script>", $rows[1][6]);
        $this->assertStringNotContainsString('Private check result', $content);
    }

    public function test_health_history_export_requires_the_website_owner(): void
    {
        [$owner, $website] = $this->infrastructure('Owner');

        $this->get(route('websites.health-checks.index', $website))->assertRedirect(route('login'));
        $this->get(route('websites.health-checks.export', $website))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('websites.health-checks.index', $website))
            ->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->get(route('websites.health-checks.export', $website))
            ->assertForbidden();
        $this->actingAs($owner)->get(route('websites.health-checks.index', $website))->assertSuccessful();
        $this->actingAs($owner)->get(route('websites.health-checks.export', $website))->assertSuccessful();
    }

    public function test_health_history_summarizes_the_filtered_retained_sample(): void
    {
        [$owner, $website] = $this->infrastructure('Insights');
        foreach ([
            [true, WebsiteHealthCheck::SOURCE_AUTOMATIC, 100, '2026-09-03 10:00:00'],
            [false, WebsiteHealthCheck::SOURCE_AUTOMATIC, 250, '2026-09-03 11:00:00'],
            [true, WebsiteHealthCheck::SOURCE_MANUAL, 200, '2026-09-03 12:00:00'],
            [false, WebsiteHealthCheck::SOURCE_MANUAL, null, '2026-09-03 13:00:00'],
        ] as [$successful, $source, $duration, $checkedAt]) {
            $website->healthChecks()->create([
                'successful' => $successful,
                'source' => $source,
                'http_status' => $successful ? 200 : 503,
                'duration_ms' => $duration,
                'endpoint' => 'http://insights.example.com/health',
                'checked_at' => $checkedAt,
            ]);
        }

        $this->actingAs($owner)->get(route('websites.health-checks.index', $website))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 4
                && $metrics['healthy'] === 2
                && $metrics['failed'] === 2
                && $metrics['success_rate'] === 50
                && $metrics['median_healthy_duration_ms'] === 150
                && $metrics['latest_at']?->toDateTimeString() === '2026-09-03 13:00:00')
            ->assertSee('Matching checks')
            ->assertSee('Healthy checks')
            ->assertSee('Failed checks')
            ->assertSee('Observed success')
            ->assertSee('50%')
            ->assertSee('Median healthy response')
            ->assertSee('150 ms')
            ->assertSee('Latest matching check');
    }

    public function test_full_history_combines_filters_and_preserves_them_across_pagination(): void
    {
        [$owner, $website] = $this->infrastructure('Filtered');
        [, $foreignWebsite] = $this->infrastructure('Foreign filtered');

        foreach (range(1, 21) as $position) {
            $website->healthChecks()->create([
                'successful' => false,
                'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
                'http_status' => 503,
                'duration_ms' => 200 + $position,
                'endpoint' => "http://filtered.example.com/match-{$position}",
                'error' => "Matching failure {$position}",
                'checked_at' => "2026-09-03 12:{$position}:00",
            ]);
        }
        $website->healthChecks()->create([
            'successful' => true,
            'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            'http_status' => 200,
            'endpoint' => 'http://filtered.example.com/healthy-excluded',
            'checked_at' => '2026-09-03 13:00:00',
        ]);
        $website->healthChecks()->create([
            'successful' => false,
            'source' => WebsiteHealthCheck::SOURCE_MANUAL,
            'endpoint' => 'http://filtered.example.com/manual-excluded',
            'checked_at' => '2026-09-03 13:01:00',
        ]);
        $website->healthChecks()->create([
            'successful' => false,
            'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            'endpoint' => 'http://filtered.example.com/date-excluded',
            'checked_at' => '2026-09-01 13:02:00',
        ]);
        $foreignWebsite->healthChecks()->create([
            'successful' => false,
            'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            'endpoint' => 'http://foreign.example.com/private-check',
            'checked_at' => '2026-09-03 13:03:00',
        ]);
        $filters = [
            'result' => 'failed',
            'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            'date_from' => '2026-09-02',
            'date_to' => '2026-09-04',
        ];

        $page = $this->actingAs($owner)->get(route('websites.health-checks.index', [$website, ...$filters]));
        $page
            ->assertSuccessful()
            ->assertViewHas('filters', $filters)
            ->assertViewHas('healthChecks', fn ($checks): bool => $checks->total() === 21 && $checks->count() === 20)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 21
                && $metrics['healthy'] === 0
                && $metrics['failed'] === 21
                && $metrics['success_rate'] === 0
                && $metrics['median_healthy_duration_ms'] === null
                && $metrics['latest_at'] !== null)
            ->assertSee('21 matching retained checks')
            ->assertSee('match-21')
            ->assertDontSee('match-1</td>', false)
            ->assertDontSee('healthy-excluded')
            ->assertDontSee('manual-excluded')
            ->assertDontSee('date-excluded')
            ->assertDontSee('private-check')
            ->assertSee('result=failed', false)
            ->assertSee('source=automatic', false)
            ->assertSee('date_from=2026-09-02', false)
            ->assertSee('date_to=2026-09-04', false);

        $this->get(route('websites.health-checks.index', [$website, ...$filters, 'page' => 2]))
            ->assertSuccessful()
            ->assertViewHas('healthChecks', fn ($checks): bool => $checks->currentPage() === 2 && $checks->count() === 1)
            ->assertSee('match-1')
            ->assertDontSee('match-21');
    }

    public function test_invalid_history_filters_are_normalized_and_filtered_empty_state_is_explicit(): void
    {
        [$owner, $website] = $this->infrastructure('Normalized');
        $website->healthChecks()->create([
            'successful' => true,
            'source' => WebsiteHealthCheck::SOURCE_MANUAL,
            'endpoint' => 'http://normalized.example.com/health',
            'checked_at' => '2026-09-03 10:00:00',
        ]);

        $this->actingAs($owner)->get(route('websites.health-checks.index', [
            $website,
            'result' => 'unknown',
            'source' => 'scheduler',
            'date_from' => '2026-02-30',
            'date_to' => 'not-a-date',
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'result' => null,
                'source' => null,
                'date_from' => null,
                'date_to' => null,
            ])
            ->assertSee('normalized.example.com/health');

        $this->get(route('websites.health-checks.index', [$website, 'result' => 'failed']))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'healthy' => 0,
                'failed' => 0,
                'success_rate' => null,
                'median_healthy_duration_ms' => null,
                'latest_at' => null,
            ])
            ->assertSee('Not available')
            ->assertSee('No health checks match these filters.');
    }

    public function test_filtered_export_matches_the_view_and_is_spreadsheet_safe(): void
    {
        [$owner, $website] = $this->infrastructure('Export filtered');
        $website->healthChecks()->create([
            'successful' => false,
            'source' => WebsiteHealthCheck::SOURCE_MANUAL,
            'http_status' => 503,
            'duration_ms' => 321,
            'endpoint' => '=DANGEROUS()',
            'error' => '+another formula',
            'checked_at' => '2026-09-03 10:00:00',
        ]);
        $website->healthChecks()->create([
            'successful' => true,
            'source' => WebsiteHealthCheck::SOURCE_MANUAL,
            'http_status' => 200,
            'endpoint' => 'http://export.example.com/healthy',
            'checked_at' => '2026-09-03 11:00:00',
        ]);
        $website->healthChecks()->create([
            'successful' => false,
            'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            'endpoint' => 'http://export.example.com/automatic',
            'checked_at' => '2026-09-03 12:00:00',
        ]);

        $response = $this->actingAs($owner)->get(route('websites.health-checks.export', [
            $website,
            'result' => 'failed',
            'source' => WebsiteHealthCheck::SOURCE_MANUAL,
            'date_from' => '2026-09-03',
            'date_to' => '2026-09-03',
        ]));
        $response
            ->assertSuccessful()
            ->assertHeader('cache-control', 'no-store, private');
        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame('failed', $rows[1][1]);
        $this->assertSame('manual', $rows[1][2]);
        $this->assertSame("'=DANGEROUS()", $rows[1][5]);
        $this->assertSame("'+another formula", $rows[1][6]);
    }

    public function test_accepted_check_parses_metrics_and_retains_only_the_newest_hundred_results(): void
    {
        [, $website] = $this->infrastructure('Bounded');
        foreach (range(1, WebsiteHealthCheck::MAX_PER_WEBSITE) as $position) {
            $website->healthChecks()->create([
                'successful' => true,
                'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
                'http_status' => 200,
                'duration_ms' => 100,
                'endpoint' => 'http://bounded.example.com/health/ready',
                'checked_at' => now()->subMinutes(101 - $position),
            ]);
        }
        $oldestId = $website->healthChecks()->oldest('checked_at')->value('id');
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getOutput')->once()->andReturn("remote banner\n204 0.0424");
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);
        $this->app->instance(Runner::class, $runner);

        app(WebsiteHealthMonitor::class)->check($website, automatic: true);

        $this->assertSame(WebsiteHealthCheck::MAX_PER_WEBSITE, $website->healthChecks()->count());
        $this->assertDatabaseMissing('website_health_checks', ['id' => $oldestId]);
        $this->assertDatabaseHas('website_health_checks', [
            'website_id' => $website->id,
            'successful' => true,
            'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            'http_status' => 204,
            'duration_ms' => 42,
            'endpoint' => 'http://bounded.example.com/health/ready',
        ]);
    }

    public function test_transport_failure_records_bounded_evidence_without_metrics(): void
    {
        [, $website] = $this->infrastructure('Transport');
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andThrow(new \RuntimeException(str_repeat('x', 700)));
        $this->app->instance(Runner::class, $runner);

        app(WebsiteHealthMonitor::class)->check($website);

        $check = $website->healthChecks()->sole();
        $this->assertFalse($check->successful);
        $this->assertSame(WebsiteHealthCheck::SOURCE_MANUAL, $check->source);
        $this->assertNull($check->http_status);
        $this->assertNull($check->duration_ms);
        $this->assertSame(500, strlen($check->error));
    }

    public function test_health_history_is_deleted_with_its_website(): void
    {
        [, $website] = $this->infrastructure('Deleted');
        $website->healthChecks()->create([
            'successful' => true,
            'source' => WebsiteHealthCheck::SOURCE_MANUAL,
            'endpoint' => 'http://deleted.example.com/health/ready',
            'checked_at' => now(),
        ]);

        $website->forceDelete();

        $this->assertDatabaseCount('website_health_checks', 0);
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

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
