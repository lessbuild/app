<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Enums\AccountRole;
use App\Models\MetricSample;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\User;
use Database\Factories\MonitorFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class MetricExplorerTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->withServices(['monitoring'])->create();
        $this->base = "/projects/{$this->project->id}/monitoring/metrics";
    }

    public function test_the_catalog_lists_this_projects_series_and_collector_setups(): void
    {
        $series = MetricSeries::factory()->create(['environment_id' => MonitorFactory::environment($this->project), 'name' => 'app.cache.hit_ratio', 'resource_label' => 'api-1']);
        MetricSeries::factory()->create(['environment_id' => MonitorFactory::environment($this->project), 'name' => 'app.queue.backlog', 'resource_label' => 'worker-7']);
        MetricSeries::factory()->create(['name' => 'foreign.metric']);

        $this->actingAs($this->ownerOf($this->project))->get($this->base)->assertOk()
            ->assertSee('app.cache.hit_ratio')->assertSee('app.queue.backlog')->assertDontSee('foreign.metric')
            ->assertSee('Linux, macOS or Windows host')->assertSee('AWS SQS (CloudWatch)')->assertSee('PostgreSQL')
            ->assertSee('Authorization: Bearer ${env:BEACON_INGEST_TOKEN}', false)->assertSee(route('api.otlp', ['signal' => 'metrics']), false)
            ->assertSee(route('monitoring.rules.create', ['project' => $this->project, 'metric' => 'numeric_metric', 'series' => $series->id]));

        $this->actingAs($this->ownerOf($this->project))->get("{$this->base}?q=worker")->assertOk()->assertSee('app.queue.backlog')->assertDontSee('app.cache.hit_ratio');
        $this->actingAs($this->ownerOf($this->project))->get("{$this->base}?q=%25")->assertOk()->assertDontSee('app.queue.backlog');
        $this->actingAs($this->ownerOf($this->project))->get("{$this->base}?kind=bogus")->assertSessionHasErrors('kind');
    }

    public function test_a_series_shows_its_chart_samples_and_identity(): void
    {
        $series = MetricSeries::factory()->create(['environment_id' => MonitorFactory::environment($this->project), 'name' => 'cpu.load', 'descriptor' => ['resource' => ['host.name' => 'db-1'], 'attributes' => [], 'scope' => []]]);
        foreach ([0.5, 0.75, 1.25] as $minutes => $value) {
            MetricSample::factory()->for($series)->create(['value' => $value, 'value_text' => (string) $value, 'occurred_at' => now()->subMinutes(10 - $minutes)]);
        }

        $this->actingAs($this->ownerOf($this->project))->get("{$this->base}/{$series->id}")->assertOk()
            ->assertSee('cpu.load')->assertSee('db-1')->assertSee('<polyline', false)->assertSee('1.25')
            ->assertSee('Anomaly detection marks unusual shifts');
        $this->actingAs($this->ownerOf($this->project))->get("{$this->base}/{$series->id}?mode=rate")->assertStatus(422);

        $this->onMonitoringTier($this->project, 'pro');
        $this->actingAs($this->ownerOf($this->project))->get("{$this->base}/{$series->id}?range=24h")->assertOk()->assertSee('Not enough history in this range');
    }

    public function test_series_in_other_projects_are_not_found_and_viewers_get_no_alert_links(): void
    {
        $foreign = MetricSeries::factory()->create();
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        MetricSeries::factory()->create(['environment_id' => MonitorFactory::environment($this->project)]);

        $this->actingAs($this->ownerOf($this->project))->get("{$this->base}/{$foreign->id}")->assertNotFound();
        $this->actingAs($viewer)->get($this->base)->assertOk()->assertDontSee('Create alert');
    }
}
