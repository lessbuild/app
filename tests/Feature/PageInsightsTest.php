<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\OperationalDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_pages_render_the_shared_responsive_insights_surface(): void
    {
        $user = User::factory()->create();

        $this->mock(OperationalDiagnostics::class)
            ->shouldReceive('run')
            ->once()
            ->andReturn([
                ['name' => 'Application key', 'passed' => true, 'detail' => 'Configured'],
            ]);

        $pages = [
            [route('projects.index'), 'projects-insights'],
            [route('backups.index'), 'backup-recovery-evidence'],
            [route('databases.index'), 'database-insights'],
            [route('domains.index'), 'domain-insights'],
            [route('load-balancers.index'), 'load-balancer-insights'],
            [route('automation.index'), 'automation-overview'],
            [route('observability.index'), 'observability-overview'],
            [route('system-health.index'), 'system-health-insights'],
            [route('organizations.index'), 'organization-insights'],
            [route('account.index'), 'account-insights'],
            [route('feedback.index'), 'feedback-insights'],
            [route('billing.index'), 'billing-insights'],
            [route('search.index', ['q' => 'no-such-resource']), 'search-insights'],
        ];

        foreach ($pages as [$url, $insightsId]) {
            $this->actingAs($user)
                ->get($url)
                ->assertSuccessful()
                ->assertSee('<details id="'.$insightsId.'"', false)
                ->assertSee('data-responsive-details', false);
        }
    }
}
