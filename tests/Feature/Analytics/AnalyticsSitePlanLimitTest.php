<?php

namespace Tests\Feature\Analytics;

use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Enums\ProductKey;
use App\Modules\Analytics\Actions\Sites\CreateSiteForWorkspace;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Validation\ValidationException;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class AnalyticsSitePlanLimitTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_finite_core_plan_limit_blocks_an_additional_analytics_site(): void
    {
        $workspace = Workspace::query()->create(['name' => 'One-site workspace']);
        $workspace->sites()->create([
            'name' => 'First site',
            'slug' => 'first-site',
            'domains' => ['first.example'],
            'timezone' => 'UTC',
        ]);

        $this->expectException(ValidationException::class);

        app(CreateSiteForWorkspace::class)->handle(
            $workspace,
            $this->plan(limit: 1),
            ['name' => 'Second site', 'slug' => 'second-site', 'domains' => ['second.example'], 'timezone' => 'UTC'],
        );
    }

    public function test_unlimited_legacy_plan_preserves_existing_site_creation(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Existing workspace']);
        $workspace->sites()->create([
            'name' => 'Existing site',
            'slug' => 'existing-site',
            'domains' => ['existing.example'],
            'timezone' => 'UTC',
        ]);

        $site = app(CreateSiteForWorkspace::class)->handle(
            $workspace,
            $this->plan(limit: null),
            ['name' => 'New site', 'slug' => 'new-site', 'domains' => ['new.example'], 'timezone' => 'UTC'],
        );

        $this->assertSame('new-site', $site->slug);
        $this->assertSame(2, $workspace->sites()->count());
    }

    private function plan(?int $limit): ProductPlanResolution
    {
        return new ProductPlanResolution(
            product: ProductKey::Analytics,
            workspaceId: '01J00000000000000000000000',
            available: true,
            planKey: 'test',
            planName: 'Test plan',
            subscriptionStatus: 'active',
            entitlements: ['site_management'],
            limits: ['sites' => $limit],
        );
    }
}
