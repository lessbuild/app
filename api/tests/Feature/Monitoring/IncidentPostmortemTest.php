<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Deployment;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Release;
use App\Models\StatusPage;
use App\Models\StatusUpdate;
use App\Services\Monitoring\IncidentSummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
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
        $base = "/api/app/projects/{$project->id}/monitoring/incidents/{$incident->id}";

        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('incident.postmortem', null)->assertJsonPath('canRespond', true);
        $this->actingAs($owner)->putJson("{$base}/postmortem", [
            'summary' => 'Checkout failed for 40 minutes.', 'impact' => 'About 12% of orders failed.',
            'root_cause' => 'A connection pool limit.', 'resolution' => 'Raised the limit and restarted workers.', 'follow_ups' => '',
        ])->assertJsonRedirect($base);
        $incident->refresh();
        $this->assertSame(['summary', 'impact', 'root_cause', 'resolution'], array_keys((array) $incident->postmortem));
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('incident.postmortem.root_cause', 'A connection pool limit.')->assertJsonPath('incident.resolvedAt', null);

        $publish = ['status_page_id' => $page->id, 'title' => 'Checkout outage', 'severity' => 'major'];
        $this->actingAs($owner)->postJson("{$base}/postmortem/publish", $publish)->assertJsonValidationErrors('status_page_id');

        $incident->forceFill(['status' => 'resolved', 'resolved_at' => now()->subHour(), 'active_slot' => null])->save();
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('incident.status', 'resolved')->assertJsonPath('report', null);
        $this->actingAs($owner)->postJson("{$base}/postmortem/publish", $publish)->assertJsonRedirect($base);
        $report = StatusUpdate::query()->sole();
        $this->assertSame(['incident', 'resolved', 'major', 'Checkout outage', 'A connection pool limit.', 'Raised the limit and restarted workers.'], [$report->kind, $report->status, $report->severity, $report->title, $report->root_cause, $report->remediation]);
        $this->assertStringContainsString('About 12% of orders failed.', $report->message);

        $this->actingAs($owner)->postJson("{$base}/postmortem/publish", [...$publish, 'title' => 'Checkout outage on Tuesday'])->assertSuccessful();
        $this->assertSame('Checkout outage on Tuesday', StatusUpdate::query()->sole()->title);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('report.statusPageId', $page->id)->assertJsonPath('report.title', 'Checkout outage on Tuesday');

        $other = StatusPage::factory()->create();
        $this->actingAs($owner)->postJson("{$base}/postmortem/publish", [...$publish, 'status_page_id' => $other->id])->assertNotFound();
    }

    /**
     * Check a new post-mortem is drafted from the incident: how long it lasted, what the check saw, the deploy just
     * before it as a lead, the notes and the timeline, while a written post-mortem is shown instead of a draft.
     *
     * @return void
     */
    public function test_a_postmortem_is_drafted_from_the_timeline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00', 'UTC'));
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->firstOrFail();
        $monitor = Monitor::factory()->create(['environment_id' => $environment->id, 'name' => 'Checkout API']);
        Deployment::factory()->create(['environment_id' => $environment->id, 'release_id' => Release::factory()->create(['project_id' => $project->id, 'version' => 'v2.4.0'])->id, 'commit_sha' => 'abcdef1234567', 'deployed_at' => now()->subMinutes(30)]);
        Deployment::factory()->create(['environment_id' => $environment->id, 'release_id' => Release::factory()->create(['project_id' => $project->id, 'version' => 'v2.3.0'])->id, 'deployed_at' => now()->subDays(2)]);
        $incident = Incident::factory()->for($monitor)->create(['title' => 'Checkout API is down', 'opened_at' => now()->subMinutes(10), 'rule_snapshot' => $monitor->snapshot()]);
        $base = "/api/app/projects/{$project->id}/monitoring/incidents/{$incident->id}";
        $this->actingAs($owner)->patchJson($base, ['action' => 'acknowledge', 'version' => $incident->refresh()->state_version])->assertSuccessful();
        $this->actingAs($owner)->patchJson($base, ['action' => 'note', 'note' => 'Pool exhausted after the deploy', 'version' => $incident->refresh()->state_version])->assertSuccessful();
        $incident->forceFill(['status' => 'resolved', 'resolved_at' => now()->addMinutes(30), 'closure_reason' => 'recovered', 'active_slot' => null])->save();

        $draft = app(IncidentSummary::class)->draft($incident->refresh());
        $this->assertStringContainsString('Checkout API is down. It opened on 23 Sep 2026, 11:50 UTC and closed 40 minutes later (recovered).', $draft['summary']);
        $this->assertStringContainsString('after 10 minutes', $draft['summary']);
        $this->assertSame('Checkout API was down for 40 minutes; it answered HTTP 503.', $draft['impact']);
        $this->assertStringContainsString('Release v2.4.0 (abcdef1) went live 20 minutes before it opened.', $draft['root_cause']);
        $this->assertStringNotContainsString('v2.3.0', $draft['root_cause']);
        $this->assertStringContainsString('Pool exhausted after the deploy', $draft['root_cause']);
        $this->assertStringContainsString('Acknowledged ('.$owner->name.')', $draft['resolution']);
        $this->assertStringContainsString('add a check or test', $draft['follow_ups']);

        $this->actingAs($owner)->getJson($base)->assertOk()->assertJson(fn (AssertableJson $json) => $json->where('draft.root_cause', fn (string $text): bool => str_contains($text, 'Release v2.4.0 (abcdef1)'))->etc());
        $incident->forceFill(['postmortem' => ['summary' => 'Written by us.']])->save();
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('incident.postmortem.summary', 'Written by us.')->assertJsonPath('draft', []);
    }
}
