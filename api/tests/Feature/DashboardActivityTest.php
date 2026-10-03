<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditAction;
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

        $this->actingAs($owner)->get('/dashboard')->assertOk()
            ->assertSee('Recent activity')
            ->assertSee("Deploy #{$build->id} of shop-app to Production")
            ->assertSee('Failed')
            ->assertSee('Checkout is down')
            ->assertSee(AuditAction::ProjectUpdated->describe([]))
            ->assertDontSee(AuditAction::PasswordChanged->describe([]))
            ->assertDontSee('Someone else’s outage');

        $this->actingAs($owner)->get('/dashboard?activity=incident')->assertOk()
            ->assertSee('Checkout is down')
            ->assertDontSee("Deploy #{$build->id} of shop-app");
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
