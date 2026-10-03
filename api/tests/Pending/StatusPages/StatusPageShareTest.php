<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPages;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Project;
use App\Models\StatusPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class StatusPageShareTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that a published page's badges show its state and uptime, the widget can be framed anywhere and links to
     * the page, and the admin page offers the snippets; drafts share nothing.
     *
     * @return void
     */
    public function test_a_published_page_has_badges_and_an_embeddable_widget(): void
    {
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $monitor = Monitor::factory()->create(['environment_id' => $project->environments()->firstOrFail()->id, 'health' => 'up', 'checked_at' => now()]);
        foreach (['up', 'up', 'up', 'down'] as $index => $outcome) {
            MonitorCheck::factory()->for($monitor)->create(['status' => 'completed', 'outcome' => $outcome, 'scheduled_at' => now()->subHours($index + 1), 'config_revision' => $monitor->config_revision]);
        }
        $page = StatusPage::factory()->create(['account_id' => $project->account_id, 'name' => 'Acme', 'slug' => 'acme', 'published' => true]);
        $page->components()->create(['monitor_id' => $monitor->id, 'label' => 'API', 'position' => 0]);

        $this->get('/status/acme/badge.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('Acme: all systems operational', false);
        $this->get('/status/acme/badge.svg?show=uptime')->assertOk()->assertSee('75% uptime');

        $embed = $this->get('/status/acme/embed')->assertOk()->assertSee('All systems operational')->assertSee('href="'.route('status.show', 'acme').'"', false);
        $this->assertStringContainsString('frame-ancestors *', (string) $embed->headers->get('Content-Security-Policy'));
        $this->assertFalse($embed->headers->has('X-Frame-Options'));

        $monitor->forceFill(['health' => 'down'])->save();
        $this->get('/status/acme/badge.svg')->assertOk()->assertSee('#dc2626', false);

        $this->actingAs($owner)->get("/projects/{$project->id}/monitoring/status-pages/{$page->id}")->assertOk()->assertSee('Share and embed')->assertSee(route('status.embed', 'acme'));

        $page->forceFill(['published' => false])->save();
        $this->get('/status/acme/badge.svg')->assertNotFound();
        $this->get('/status/acme/embed')->assertNotFound();
    }
}
