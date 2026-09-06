<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationalDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_health_requires_a_verified_account(): void
    {
        $this->get(route('system-health.index'))->assertRedirect(route('login'));
        $this->get(route('system-health.report'))->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)
            ->get(route('system-health.index'))
            ->assertRedirect(route('verification.notice'));
        $this->actingAs($unverified)
            ->get(route('system-health.report'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_account_can_review_a_fresh_safe_health_snapshot(): void
    {
        $user = User::factory()->create();
        $this->mock(OperationalDiagnostics::class)
            ->shouldReceive('run')
            ->once()
            ->andReturn([
                ['name' => 'Application key', 'passed' => true, 'detail' => 'Configured'],
                ['name' => 'Pending queue state', 'passed' => true, 'detail' => '0 pending jobs'],
            ]);

        $this->actingAs($user)->get(route('system-health.index'))
            ->assertSuccessful()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('pragma', 'no-cache')
            ->assertSee('System Health')
            ->assertSee('Operational')
            ->assertSee('2 of 2 checks passed')
            ->assertSee('Application key')
            ->assertSee('Pending queue state')
            ->assertSee('Download report')
            ->assertSee(route('system-health.report'))
            ->assertSee('Run checks again')
            ->assertSee(route('system-health.index'));
    }

    public function test_detailed_health_is_restricted_to_workspace_owners_and_admins(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->currentOrganization;

        $viewer = User::factory()->create();
        $organization->members()->syncWithoutDetaching([$viewer->id => ['role' => 'viewer']]);
        $viewer->update(['current_organization_id' => $organization->id]);

        $developer = User::factory()->create();
        $organization->members()->syncWithoutDetaching([$developer->id => ['role' => 'developer']]);
        $developer->update(['current_organization_id' => $organization->id]);

        $admin = User::factory()->create();
        $organization->members()->syncWithoutDetaching([$admin->id => ['role' => 'admin']]);
        $admin->update(['current_organization_id' => $organization->id]);

        foreach ([$viewer, $developer] as $member) {
            $this->actingAs($member)->get(route('system-health.index'))->assertForbidden();
            $this->actingAs($member)->get(route('system-health.report'))->assertForbidden();
        }

        $this->actingAs($admin)->get(route('system-health.index'))->assertOk();
    }

    public function test_verified_account_can_download_a_private_machine_readable_report(): void
    {
        $user = User::factory()->create();
        $this->mock(OperationalDiagnostics::class)
            ->shouldReceive('run')
            ->once()
            ->andReturn([
                ['name' => 'Application key', 'passed' => true, 'detail' => 'Configured'],
                ['name' => 'Failed queue jobs', 'passed' => false, 'detail' => '1 failed job requires review'],
            ]);

        $response = $this->actingAs($user)->get(route('system-health.report'))
            ->assertSuccessful()
            ->assertHeader('content-type', 'application/json')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('pragma', 'no-cache')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('content-disposition');

        $this->assertMatchesRegularExpression(
            '/attachment; filename="lessbuild-system-health-\d{8}-\d{6}\.json"/',
            (string) $response->headers->get('content-disposition'),
        );
        $response
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('summary.passed', 1)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('checks.1.name', 'Failed queue jobs')
            ->assertJsonMissingPath('application_key');
        $this->assertNotNull($response->json('generated_at'));
    }

    public function test_failed_health_snapshot_is_explicit_and_escapes_diagnostic_text(): void
    {
        $user = User::factory()->create();
        $this->mock(OperationalDiagnostics::class)
            ->shouldReceive('run')
            ->once()
            ->andReturn([
                ['name' => 'Database connection', 'passed' => true, 'detail' => 'sqlite'],
                ['name' => 'Queue worker <script>', 'passed' => false, 'detail' => 'Inactive <script>alert(1)</script>'],
            ]);

        $this->actingAs($user)->get(route('system-health.index'))
            ->assertSuccessful()
            ->assertSee('Needs attention')
            ->assertSee('1 of 2 checks passed')
            ->assertSee('Fail')
            ->assertSee('Queue worker &lt;script&gt;', false)
            ->assertSee('Inactive &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_live_diagnostics_do_not_disclose_the_application_key(): void
    {
        $secret = 'base64:'.base64_encode(str_repeat('s', 32));
        config([
            'app.key' => $secret,
            'lessbuild.diagnostics.systemd_timers' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('system-health.index'))
            ->assertSuccessful()
            ->assertSee('Application key')
            ->assertDontSee($secret);

        $this->actingAs(User::factory()->create())
            ->get(route('system-health.report'))
            ->assertSuccessful()
            ->assertDontSee($secret);
    }
}
