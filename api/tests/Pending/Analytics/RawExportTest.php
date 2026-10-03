<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\StorageBucket;
use App\Models\User;
use App\Services\Analytics\RawEventExporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RawExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that a site's raw events are exported a day at a time to its project's bucket, and that other projects'
     * buckets and bad folders are refused.
     *
     * @return void
     */
    public function test_raw_events_are_exported_each_day_to_the_bucket(): void
    {
        $status = 200;
        Http::fake(['https://storage.googleapis.com/*' => function () use (&$status) {
            return Http::response('', $status);
        }]);
        $this->travelTo(CarbonImmutable::parse('2026-09-20 03:00', 'UTC'));
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics', 'infrastructure'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['timezone' => 'UTC']);
        $bucket = (new StorageBucket)->forceFill(['project_id' => $project->id, 'name' => 'Warehouse', 'storage_provider' => 'google_cloud_storage', 'endpoint' => 'https://storage.googleapis.com', 'region' => 'auto', 'bucket' => 'acme-raw', 'access_key' => 'GOOG1', 'secret_key' => 'secret']);
        $bucket->save();
        $foreign = (new StorageBucket)->forceFill(['project_id' => Project::factory()->create()->id, 'name' => 'Theirs', 'storage_provider' => 'amazon_s3', 'endpoint' => 'https://s3.amazonaws.com', 'region' => 'us-east-1', 'bucket' => 'theirs', 'access_key' => 'a', 'secret_key' => 'b']);
        $foreign->save();
        $page = "/projects/{$project->id}/analytics/sites/{$site->id}";

        $this->actingAs($owner)->put("{$page}/raw-export", ['export_bucket_id' => $foreign->id])->assertSessionHasErrors('export_bucket_id');
        $this->actingAs($owner)->put("{$page}/raw-export", ['export_bucket_id' => $bucket->id, 'export_prefix' => '../x y'])->assertSessionHasErrors('export_prefix');
        $this->actingAs($owner)->put("{$page}/raw-export", ['export_bucket_id' => $bucket->id, 'export_prefix' => '/analytics/'])->assertRedirect($page);
        $this->assertSame('analytics', $site->refresh()->export_prefix);
        $this->actingAs($owner)->get($page)->assertOk()->assertSee(__('Raw data export'))->assertSee('analytics/site='.$site->public_id, false);

        foreach (['2026-09-19 10:00', '2026-09-19 23:59', '2026-09-20 01:00'] as $index => $at) {
            AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => CarbonImmutable::parse($at, 'UTC'), 'received_at' => now(), 'path' => "/page-{$index}", 'channel' => 'Direct']);
        }
        $exporter = app(RawEventExporter::class);
        $this->assertSame(1, $exporter->exportDue(), 'Yesterday only: today isn’t finished.');
        $this->assertSame(0, $exporter->exportDue());
        $this->assertSame('2026-09-19', $site->refresh()->exported_until?->toDateString());
        Http::assertSent(function (Request $request) use ($site): bool {
            $lines = array_values(array_filter(explode("\n", (string) gzdecode($request->body()))));

            return $request->method() === 'PUT'
                && rawurldecode($request->url()) === 'https://storage.googleapis.com/acme-raw/analytics/site='.$site->public_id.'/dt=2026-09-19/events.ndjson.gz'
                && count($lines) === 2 && json_decode($lines[0], true)['path'] === '/page-0' && json_decode($lines[1], true)['channel'] === 'Direct';
        });

        $status = 403;
        $this->travelTo(CarbonImmutable::parse('2026-09-21 03:00', 'UTC'));
        $this->assertSame(0, $exporter->exportDue());
        $this->assertStringContainsString('403', (string) $site->refresh()->export_error);
        $this->assertSame('2026-09-19', $site->exported_until?->toDateString(), 'A failed day is tried again next time.');

        $this->actingAs($owner)->put("{$page}/raw-export", ['export_bucket_id' => ''])->assertRedirect($page);
        $this->assertNull($site->refresh()->export_bucket_id);
    }
}
