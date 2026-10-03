<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AlertNoiseTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check the noise report ranks a flapping monitor above a rule people act on, counts flaps and unacknowledged
     * incidents, suggests a fix for the flapping one only, and leaves out old incidents and other projects.
     *
     * @return void
     */
    public function test_the_noise_report_finds_flapping_alerts(): void
    {
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->firstOrFail();
        $monitor = Monitor::factory()->create(['environment_id' => $environment->id, 'name' => 'Homepage']);
        $rule = AlertRule::factory()->ready()->create(['environment_id' => $environment->id, 'name' => 'Error rate']);
        // The monitor flapped four times (two minutes each, nobody looked) and was down properly once.
        foreach ([2, 2, 2, 2, 45] as $index => $minutes) {
            $opened = now()->subDays($index + 1);
            Incident::factory()->for($monitor)->create(['opened_at' => $opened, 'active_slot' => null, 'status' => 'resolved', 'resolved_at' => $opened->addMinutes($minutes), 'closure_reason' => 'recovered', 'acknowledged_at' => $minutes > 5 ? $opened->addMinute() : null]);
        }
        $opened = now()->subDays(3);
        Incident::factory()->for($rule)->create(['opened_at' => $opened, 'active_slot' => null, 'status' => 'resolved', 'resolved_at' => $opened->addMinutes(30), 'closure_reason' => 'recovered', 'acknowledged_at' => $opened->addMinutes(2)]);
        Incident::factory()->for($monitor)->create(['opened_at' => now()->subDays(40), 'active_slot' => null, 'status' => 'resolved', 'resolved_at' => now()->subDays(40), 'closure_reason' => 'recovered']);
        Incident::factory()->create(['opened_at' => now()->subDay()]);

        $this->actingAs($owner)->get("/projects/{$project->id}/monitoring/alerts/noise")->assertOk()
            ->assertSeeInOrder(['Homepage', '5', '4', '4', '(80%)', 'It mostly flaps', 'Error rate', '1', '0', '0', '(0%)'])
            ->assertSee(__('Noise'));
    }
}
