<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['lessbuild.platform_admin_emails' => ['owner@example.com']]);
    }

    public function test_only_configured_platform_admins_can_view_business_analytics(): void
    {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $ordinary = User::factory()->create(['email' => 'member@example.com']);

        $this->actingAs($ordinary)->get(route('admin.analytics'))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Business analytics')
            ->assertSee('New users · 30 days')
            ->assertSee('Deployments · 30 days')
            ->assertSee('Plan distribution')
            ->assertSee('Estimated MRR');
    }

    public function test_paid_feature_denials_are_counted_without_personal_data(): void
    {
        config(['billing.enforce_entitlements' => true]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);

        try {
            app(Entitlements::class)->enforce($admin->currentOrganization, 'api');
        } catch (ValidationException) {
            // Expected: the denial is the event being measured.
        }

        $response = $this->actingAs($admin)->get(route('admin.analytics'));
        $response->assertOk()->assertViewHas('totals', fn (array $totals): bool => $totals['denials_30d'] === 1);
        $this->assertSame(1, Cache::get('business:denials:'.now()->utc()->toDateString().':total'));
    }
}
