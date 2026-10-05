<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\AuditAction;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsSite;
use App\Models\AuditEntry;
use App\Models\Build;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DashboardActivityTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that the dashboard shows the account's deploys, incidents and team changes, filters them, and leaves out
     * other accounts and personal security events.
     *
     * @return void
     */
    /**
     * The dashboard shows the team's recent deploys, incidents and changes (not personal ones, nor other accounts'),
     * filtered by kind.
     */
    public function test_the_dashboard_shows_the_teams_recent_activity(): void
    {
        $project = Project::factory()->withServices(['deploy', 'monitoring'])->create(['name' => 'Storefront']);
        $owner = $this->ownerOf($project);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $provider = Provider::factory()->create(['account_id' => $project->account_id]);
        $website = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => $provider->id])->id]);
        $repository = Repository::factory()->create(['name' => 'shop-app', 'website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => $provider->id, 'environment_id' => $production->id]);
        $build = Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'status' => Build::STATUS_FAILED, 'finished_at' => now()->subMinutes(5)]);
        Incident::factory()->for(Monitor::factory()->create(['environment_id' => $production->id]))->create(['title' => 'Checkout is down', 'opened_at' => now()->subMinutes(2)]);
        $this->audit($project->account_id, $project->id, AuditAction::ProjectUpdated, $owner->name);
        $this->audit($project->account_id, null, AuditAction::PasswordChanged, $owner->name);

        $other = Project::factory()->withServices(['monitoring'])->create();
        Incident::factory()->for(Monitor::factory()->create(['environment_id' => $other->environments()->firstOrFail()->id]))->create(['title' => 'Someone else’s outage']);

        $this->actingAs($owner)->getJson('/api/app/dashboard')->assertOk()
            ->assertSee("Deploy #{$build->id} of shop-app to Production")
            ->assertSee('Failed')
            ->assertSee('Checkout is down')
            ->assertSee(AuditAction::ProjectUpdated->describe([]))
            ->assertDontSee(AuditAction::PasswordChanged->describe([]))
            ->assertDontSee('Someone else’s outage');

        $this->actingAs($owner)->getJson('/api/app/dashboard?activity=incident')->assertOk()->assertJsonPath('activityKind', 'incident')
            ->assertSee('Checkout is down')
            ->assertDontSee("Deploy #{$build->id} of shop-app");
    }

    /**
     * The dashboard lists what needs attention (open incidents, deploys whose latest attempt failed) and gives each
     * project its services, health, last deploy and recent visitors.
     */
    public function test_the_dashboard_flags_what_needs_attention_and_each_projects_health(): void
    {
        $project = Project::factory()->withServices(['deploy', 'monitoring', 'analytics'])->create(['name' => 'Storefront']);
        $owner = $this->ownerOf($project);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $provider = Provider::factory()->create(['account_id' => $project->account_id]);
        $website = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => $provider->id])->id]);
        $repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => $provider->id, 'environment_id' => $production->id]);
        Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'status' => Build::STATUS_FAILED, 'failure_message' => 'Migrations failed']);
        $incident = Incident::factory()->for(Monitor::factory()->create(['environment_id' => $production->id]))->create(['title' => 'Checkout is down']);

        $dashboard = $this->actingAs($owner)->getJson('/api/app/dashboard')->assertOk()
            ->assertJsonPath('projects.0.serviceKeys', ['deploy', 'monitoring', 'analytics'])
            ->assertJsonPath('projects.0.health', 'degraded')
            ->assertJsonPath('projects.0.openIncidents', 1)
            ->assertJsonPath('projects.0.lastDeployAt', null)
            ->assertJsonCount(10, 'projects.0.visitors')
            ->assertJsonPath('attention.0.kind', 'incident')
            ->assertJsonPath('attention.1.kind', 'deploy');
        $this->assertStringContainsString('Checkout is down', (string) $dashboard->json('attention.0.text'));
        $this->assertSame('Migrations failed', $dashboard->json('attention.1.text'));

        // A later successful deploy clears the failure; resolving the incident makes the project healthy.
        Build::factory()->succeeded()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'finished_at' => now()]);
        $incident->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'active_slot' => null])->save();
        $site = AnalyticsSite::factory()->create(['project_id' => $project->id]);
        AnalyticsDailyAggregate::query()->create(['site_id' => $site->id, 'local_date' => today()->toDateString(), 'dimension' => 'all', 'dimension_value' => '', 'pageviews' => 9, 'visits' => 4, 'visitors' => 3, 'conversions' => 0, 'converted_visits' => 0, 'bounce_eligible' => 0, 'bounces' => 0]);

        $dashboard = $this->actingAs($owner)->getJson('/api/app/dashboard')->assertOk()
            ->assertJsonPath('projects.0.health', 'healthy')
            ->assertJsonPath('projects.0.visitors.9', 3)
            ->assertJsonCount(0, 'attention');
        $this->assertNotNull($dashboard->json('projects.0.lastDeployAt'));
    }

    /**
     * Record an audit entry directly.
     *
     * @param  string  $accountId
     * @param  string|null  $projectId
     * @param  AuditAction  $action
     * @param  string  $actor
     * @return void
     */
    private function audit(string $accountId, ?string $projectId, AuditAction $action, string $actor): void
    {
        (new AuditEntry)->forceFill([
            'id' => (string) Str::ulid(), 'account_id' => $accountId, 'project_id' => $projectId, 'action' => $action,
            'actor_name' => $actor, 'context' => [], 'created_at' => now()->subMinutes(10),
        ])->save();
    }
}
