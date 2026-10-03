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
        $this->base = "/projects/{$this->project->id}/monitoring/dashboards";
    }

    public function test_owner_creates_changes_and_deletes_a_dashboard(): void
    {
        $this->onMonitoringTier($this->project, 'pro');
        $this->actingAs($this->owner)->get("{$this->base}/create")->assertOk()->assertSee('Telemetry summary');
        $this->actingAs($this->owner)->post($this->base, ['name' => 'Production', 'range' => '7d', 'widgets' => ['incidents', 'telemetry']])->assertRedirect();

        $dashboard = Dashboard::query()->sole();
        $this->assertSame($this->owner->id, $dashboard->created_by);
        $this->assertSame(['incidents', 'telemetry'], $dashboard->widgets()->pluck('type')->all());
        $this->actingAs($this->owner)->get($this->base)->assertOk()->assertSee('Production')->assertSee('2 widgets');

        $this->actingAs($this->owner)->put("{$this->base}/{$dashboard->id}", ['name' => 'Ops', 'range' => '24h', 'widgets' => ['monitors']])->assertRedirect("{$this->base}/{$dashboard->id}");
        $this->assertSame(['monitors'], $dashboard->widgets()->pluck('type')->all());
        $this->assertSame('Ops', $this->reload($dashboard)->name);

        $this->actingAs($this->owner)->delete("{$this->base}/{$dashboard->id}")->assertRedirect($this->base);
        $this->assertModelMissing($dashboard);
        $this->assertDatabaseCount('dashboard_widgets', 0);
        $this->assertSame(
            [AuditAction::DashboardCreated, AuditAction::DashboardUpdated, AuditAction::DashboardDeleted],
            AuditEntry::query()->where('account_id', $this->project->account_id)->orderBy('id')->pluck('action')->all(),
        );
    }

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

        $this->actingAs($this->owner)->get("{$this->base}/{$dashboard->id}")->assertOk()
            ->assertSee('Telemetry summary')->assertSee('Requests per period')
            ->assertSee('Event mix')->assertSee('Blog homepage is down')->assertDontSee('Someone else’s outage')
            ->assertSee('Blog homepage')->assertSee('Checkout availability')->assertSee('Shop')->assertSee('Blog');
    }

    public function test_the_plan_limits_new_dashboards(): void
    {
        Dashboard::factory()->for($this->project->account)->create();

        $this->actingAs($this->owner)->post($this->base, ['name' => 'Second', 'range' => '24h', 'widgets' => ['telemetry']])
            ->assertSessionHasErrors(['plan' => 'Your Monitoring plan allows 1 dashboard. Upgrade to add more.']);
        $this->assertDatabaseCount('dashboards', 1);
        $this->actingAs($this->owner)->get($this->base)->assertSee('1 of 1 dashboards on your plan');
    }

    public function test_failed_submission_keeps_an_empty_widget_choice(): void
    {
        $this->actingAs($this->owner)->from("{$this->base}/create")->post($this->base, ['name' => 'Empty', 'range' => '24h'])
            ->assertRedirect("{$this->base}/create")->assertSessionHasErrors(['widgets' => 'Choose at least one widget.']);

        $response = $this->withCookie(session()->getName(), session()->getId())->get("{$this->base}/create")->assertOk();
        $this->assertSame(0, $this->xpathCount($response, '//input[@name="widgets[]"][@checked]'));
    }

    public function test_viewers_read_dashboards_but_only_admins_manage_them(): void
    {
        $dashboard = Dashboard::factory()->for($this->project->account)->withWidgets()->create(['name' => 'Shared']);
        $foreign = Dashboard::factory()->create();
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);

        $this->actingAs($member)->get($this->base)->assertOk()->assertSee('Shared')->assertDontSee('Add a dashboard');
        $this->actingAs($member)->get("{$this->base}/{$dashboard->id}")->assertOk()->assertDontSee('>Edit<', false);
        $this->actingAs($member)->get("{$this->base}/create")->assertForbidden();
        $this->actingAs($member)->put("{$this->base}/{$dashboard->id}", ['name' => 'Nope', 'range' => '24h', 'widgets' => ['telemetry']])->assertForbidden();
        $this->actingAs($member)->delete("{$this->base}/{$dashboard->id}")->assertForbidden();
        $this->actingAs($this->owner)->get("{$this->base}/{$foreign->id}")->assertNotFound();
        $this->assertModelExists($dashboard);
    }
}
