<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Data\Monitoring\MonitorObservation;
use App\Jobs\Infrastructure\HealWebsite;
use App\Models\IncidentActivity;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use App\Services\Monitoring\MonitorResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class SelfHealingTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * Check a website that heals itself gets its stopped services restarted when its health check opens an incident,
     * what was done goes on the incident, it stops after three tries an hour, a website without it is left alone, and
     * the script is valid bash.
     *
     * @return void
     */
    public function test_a_failing_website_heals_itself(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['infrastructure', 'monitoring'])->create();
        $environment = $project->environments()->firstOrFail();
        $server = Server::factory()->create(['account_id' => $project->account_id, 'provisioning_status' => Server::STATUS_ACTIVE, 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $monitor = Monitor::factory()->create(['environment_id' => $environment->id, 'trigger_checks' => 1, 'recovery_checks' => 1]);
        $website = Website::factory()->create(['server_id' => $server->id, 'environment_id' => $environment->id, 'deployment_slug' => 'shop', 'health_monitor_id' => $monitor->id, 'self_healing' => true]);
        $fail = function () use ($monitor): void {
            app(MonitorResults::class)->record($monitor->refresh(), new MonitorObservation('down', 'unexpected_status', 502), now()->toImmutable(), 'test');
        };
        $recover = function () use ($monitor): void {
            app(MonitorResults::class)->record($monitor->refresh(), new MonitorObservation('up', 'passed', 200), now()->toImmutable(), 'test');
        };

        $this->shell->reply("restarted php8.4-fpm\n");
        $fail();
        $this->assertStringContainsString('systemctl restart "$unit"', $this->shell->ran[0]['command']);
        $healed = IncidentActivity::query()->where('action', 'self_healed')->sole();
        $this->assertSame('Did: restarted php8.4-fpm.', $healed->note);

        foreach (range(1, 3) as $attempt) {
            $recover();
            $this->shell->reply("reloaded php8.4-fpm\nreloaded caddy\n");
            $fail();
        }
        $this->assertSame(3, IncidentActivity::query()->where('action', 'self_healed')->count());
        $this->assertSame(1, IncidentActivity::query()->where('action', 'self_heal_skipped')->count(), 'Not more than three times an hour.');

        $website->forceFill(['self_healing' => false])->save();
        $recover();
        $runs = count($this->shell->ran);
        $fail();
        $this->assertCount($runs, $this->shell->ran, 'Without self-healing, nothing runs.');

        exec('bash -n <<\'SCRIPT\''."\n".HealWebsite::script($website)."\nSCRIPT\n".' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}
