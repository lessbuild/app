<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class BreadcrumbsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Get the breadcrumb trail of a page as its labels, in order.
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return list<string>
     */
    private function trail(TestResponse $response): array
    {
        preg_match('/<nav aria-label="Breadcrumb".*?<\/nav>/s', (string) $response->getContent(), $nav);
        preg_match_all('/<(?:a|span|li aria-current="page")[^>]*>([^<]+)</', $nav[0] ?? '', $labels);

        return array_values(array_filter(array_map(fn (string $label): string => html_entity_decode(trim($label)), $labels[1]), fn (string $label): bool => $label !== ''));
    }

    /**
     * Check that each step of the trail goes one level up: a detail page leads back to its section's list, and the
     * section to the service and the project.
     *
     * @return void
     */
    public function test_the_trail_steps_up_through_the_service_and_its_section(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create(['name' => 'Shop']);
        $site = AnalyticsSite::factory()->for($project)->create(['name' => 'Shop site']);
        $base = "/projects/{$project->id}";

        $this->assertSame(['Projects', 'Shop'], $this->trail($this->actingAs($owner)->get($base)));
        $this->assertSame(['Projects', 'Shop', 'Project settings'], $this->trail($this->actingAs($owner)->get("{$base}/settings")));
        $this->assertSame(['Projects', 'Shop', 'Analytics'], $this->trail($this->actingAs($owner)->get("{$base}/analytics")));
        $this->assertSame(['Projects', 'Shop', 'Analytics', 'Goals'], $this->trail($this->actingAs($owner)->get("{$base}/analytics/goals")));
        $page = $this->actingAs($owner)->get("{$base}/analytics/sites/{$site->id}");
        $this->assertSame(['Projects', 'Shop', 'Analytics', 'Sites', 'Shop site'], $this->trail($page));
        $page->assertSee('href="'.route('analytics.sites', $project).'"', false);
    }
}
