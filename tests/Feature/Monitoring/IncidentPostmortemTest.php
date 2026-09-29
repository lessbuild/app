<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\StatusUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IncidentPostmortemTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that a post-mortem is written from the template, noted on the timeline, and published (then updated) as a
     * resolved report on a status page, only once the incident is resolved.
     *
     * @return void
     */
    public function test_a_postmortem_is_written_and_published_to_a_status_page(): void
    {
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $monitor = Monitor::factory()->create(['environment_id' => $project->environments()->firstOrFail()->id]);
        $incident = Incident::factory()->for($monitor)->create(['title' => 'Checkout API down', 'opened_at' => now()->subHours(2)]);
        $page = StatusPage::factory()->create(['account_id' => $project->account_id, 'slug' => 'acme', 'published' => true]);
        $base = "/projects/{$project->id}/monitoring/incidents/{$incident->id}";

        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Write a post-mortem');
        $this->actingAs($owner)->put("{$base}/postmortem", [
            'summary' => 'Checkout failed for 40 minutes.', 'impact' => 'About 12% of orders failed.',
            'root_cause' => 'A connection pool limit.', 'resolution' => 'Raised the limit and restarted workers.', 'follow_ups' => '',
        ])->assertRedirect($base);
        $incident->refresh();
        $this->assertSame(['summary', 'impact', 'root_cause', 'resolution'], array_keys((array) $incident->postmortem));
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Post-mortem written')->assertSee('A connection pool limit.')->assertDontSee('Publish to status page');

        $publish = ['status_page_id' => $page->id, 'title' => 'Checkout outage', 'severity' => 'major'];
        $this->actingAs($owner)->post("{$base}/postmortem/publish", $publish)->assertSessionHasErrors('status_page_id');

        $incident->forceFill(['status' => 'resolved', 'resolved_at' => now()->subHour(), 'active_slot' => null])->save();
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Publish to status page');
        $this->actingAs($owner)->post("{$base}/postmortem/publish", $publish)->assertRedirect($base);
        $report = StatusUpdate::query()->sole();
        $this->assertSame(['incident', 'resolved', 'major', 'Checkout outage', 'A connection pool limit.', 'Raised the limit and restarted workers.'], [$report->kind, $report->status, $report->severity, $report->title, $report->root_cause, $report->remediation]);
        $this->assertStringContainsString('About 12% of orders failed.', $report->message);
        $this->get('/status/acme')->assertOk()->assertSee('Checkout outage')->assertSee('A connection pool limit.');

        $this->actingAs($owner)->post("{$base}/postmortem/publish", [...$publish, 'title' => 'Checkout outage on Tuesday'])->assertRedirect();
        $this->assertSame('Checkout outage on Tuesday', StatusUpdate::query()->sole()->title);
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Post-mortem published to a status page')->assertSee('Update status page report');

        $other = StatusPage::factory()->create();
        $this->actingAs($owner)->post("{$base}/postmortem/publish", [...$publish, 'status_page_id' => $other->id])->assertNotFound();
    }
}
