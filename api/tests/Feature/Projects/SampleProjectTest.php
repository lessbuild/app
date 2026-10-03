<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Account;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SampleProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sample_project_fills_analytics_and_monitoring_with_made_up_data(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $owner->forceFill(['current_account_id' => $account->id])->save();

        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('Explore a sample project');
        $response = $this->actingAs($owner)->post('/projects/sample');

        $project = Project::query()->sole();
        $response->assertRedirect(route('analytics.overview', $project));
        $this->assertTrue($project->is_sample);
        $site = AnalyticsSite::query()->where('project_id', $project->id)->sole();
        $this->assertFalse($site->collection_enabled);
        $this->assertGreaterThan(0, AnalyticsDailyAggregate::query()->where('site_id', $site->id)->count());
        $this->assertSame(0, \App\Models\AnalyticsEvent::query()->where('occurred_at', '>', now())->count());
        $this->assertGreaterThan(100, TelemetryEvent::query()->where('type', 'request')->count());
        $this->assertGreaterThan(0, TelemetryEvent::query()->where('type', 'exception')->count());
        $this->assertSame(0, (int) DB::table('usage_records')->where('meter', 'analytics.pageviews')->sum('quantity'));

        $this->actingAs($owner)->get(route('analytics.overview', $project))->assertOk()->assertSee('This is a sample project')->assertSee('storefront.example');
        $this->actingAs($owner)->get("/projects/{$project->id}/monitoring/issues")->assertOk()->assertSee('Card was declined by the payment gateway');
    }
}
