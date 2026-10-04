<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\Dashboard;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\TelemetryEvent;
use App\Models\User;
use Database\Factories\MonitorFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->withServices(['monitoring'])->create(['name' => 'Shop']);
        $this->owner = $this->ownerOf($this->project);
        $this->base = "/api/app/projects/{$this->project->id}/monitoring/dashboards";
    }

    /**
     * Owner creates changes and deletes a dashboard.
     */
    public function test_owner_creates_changes_and_deletes_a_dashboard(): void
    {
        $this->onMonitoringTier($this->project, 'pro');
        $this->actingAs($this->owner)->getJson("{$this->base}/create")->assertOk()->assertSee(__('Telemetry summary'));
        $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Production', 'range' => '7d', 'widgets' => ['incidents', 'telemetry']])->assertSuccessful();

        $dashboard = Dashboard::query()->sole();
        $this->assertSame($this->owner->id, $dashboard->created_by);
        $this->assertSame(['incidents', 'telemetry'], $dashboard->widgets()->pluck('type')->all());
        $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertJsonPath('dashboards.0.name', 'Production')->assertJsonPath('dashboards.0.widgets', 2);

        $this->actingAs($this->owner)->putJson("{$this->base}/{$dashboard->id}", ['name' => 'Ops', 'range' => '24h', 'widgets' => ['monitors']])->assertJsonRedirect("{$this->base}/{$dashboard->id}");
        $this->assertSame(['monitors'], $dashboard->widgets()->pluck('type')->all());
        $this->assertSame('Ops', $this->reload($dashboard)->name);

        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$dashboard->id}")->assertJsonRedirect($this->base);
        $this->assertModelMissing($dashboard);
        $this->assertDatabaseCount('dashboard_widgets', 0);
        $this->assertSame(
            [AuditAction::DashboardCreated, AuditAction::DashboardUpdated, AuditAction::DashboardDeleted],
            AuditEntry::query()->where('account_id', $this->project->account_id)->orderBy('id')->pluck('action')->all(),
        );
    }

    /**
     * Every widget renders account wide data.
     */
    public function test_every_widget_renders_account_wide_data(): void
    {
        $environment = MonitorFactory::environment($this->project);
        $other = Project::factory()->for($this->project->account)->withServices(['monitoring'])->create(['name' => 'Blog']);
        $monitor = Monitor::factory()->create(['environment_id' => MonitorFactory::environment($other), 'name' => 'Blog homepage']);
        Incident::factory()->for($monitor)->create(['title' => 'Blog homepage is down']);
        ServiceLevelObjective::factory()->create(['environment_id' => $environment, 'name' => 'Checkout availability']);
        TelemetryEvent::factory()->create(['environment_id' => $environment, 'occurred_at' => now()->subHour()]);
        Incident::factory()->create(['title' => 'Someone else’s outage']);
        $dashboard = Dashboard::factory()->for($this->project->account)->withWidgets(array_keys(Dashboard::WIDGETS))->create();

        $this->actingAs($this->owner)->getJson("{$this->base}/{$dashboard->id}")->assertOk()
            ->assertJsonCount(count(Dashboard::WIDGETS), 'widgets')->assertSee('Telemetry summary')
            ->assertSee('Event mix')->assertSee('Blog homepage is down')->assertDontSee('Someone else’s outage')
            ->assertSee('Blog homepage')->assertSee('Checkout availability')->assertSee('Shop')->assertSee('Blog');
    }

    /**
     * The plan limits new dashboards.
     */
    public function test_the_plan_limits_new_dashboards(): void
    {
        Dashboard::factory()->for($this->project->account)->create();

        $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Second', 'range' => '24h', 'widgets' => ['telemetry']])
            ->assertJsonValidationErrors(['plan' => 'Your Monitoring plan allows 1 dashboard. Upgrade to add more.']);
        $this->assertDatabaseCount('dashboards', 1);
        $this->actingAs($this->owner)->getJson($this->base)->assertJsonPath('limit', 1)->assertJsonCount(1, 'dashboards');
    }

    /**
     * Failed submission keeps an empty widget choice.
     */
    public function test_failed_submission_keeps_an_empty_widget_choice(): void
    {
        $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Empty', 'range' => '24h'])
            ->assertJsonValidationErrors(['widgets' => 'Choose at least one widget.']);
        $this->assertDatabaseCount('dashboards', 0);
    }

    /**
     * Viewers read dashboards but only admins manage them.
     */
    public function test_viewers_read_dashboards_but_only_admins_manage_them(): void
    {
        $dashboard = Dashboard::factory()->for($this->project->account)->withWidgets()->create(['name' => 'Shared']);
        $foreign = Dashboard::factory()->create();
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);

        $this->actingAs($member)->getJson($this->base)->assertOk()->assertSee('Shared')->assertDontSee('Add a dashboard');
        $this->actingAs($member)->getJson("{$this->base}/{$dashboard->id}")->assertOk()->assertDontSee('>Edit<', false);
        $this->actingAs($member)->getJson("{$this->base}/create")->assertForbidden();
        $this->actingAs($member)->putJson("{$this->base}/{$dashboard->id}", ['name' => 'Nope', 'range' => '24h', 'widgets' => ['telemetry']])->assertForbidden();
        $this->actingAs($member)->deleteJson("{$this->base}/{$dashboard->id}")->assertForbidden();
        $this->actingAs($this->owner)->getJson("{$this->base}/{$foreign->id}")->assertNotFound();
        $this->assertModelExists($dashboard);
    }
}
