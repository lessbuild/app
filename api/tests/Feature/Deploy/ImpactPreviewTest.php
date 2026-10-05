<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ImpactPreviewTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Changed paths are checked against each repository's path filters, the same way push deploys are, and nothing is
     * deployed.
     */
    public function test_changed_paths_say_which_repositories_would_deploy(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['deploy'])->create();
        $owner = $this->ownerOf($project);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id]);
        Repository::factory()->create(['project_id' => $project->id, 'website_id' => $website->id, 'provider_id' => $github->id, 'name' => 'api', 'auto_deploy_include_paths' => ['apps/api/**', 'packages/**']]);
        Repository::factory()->create(['project_id' => $project->id, 'website_id' => $website->id, 'provider_id' => $github->id, 'name' => 'web', 'auto_deploy_include_paths' => ['apps/web/**']]);

        $response = $this->actingAs($owner)->postJson("/api/app/projects/{$project->id}/deploy/impact-preview", ['paths' => "apps/api/routes.php\n\ndocs/readme.md\n"])->assertOk()->assertJsonPath('paths', 2);
        $rows = collect((array) $response->json('repositories'))->keyBy('name');
        $this->assertSame('affected', $rows['api']['decision']);
        $this->assertSame(['apps/api/routes.php'], $rows['api']['matched']);
        $this->assertSame('unaffected', $rows['web']['decision']);
        $this->assertSame(0, \App\Models\Build::query()->count());
    }
}
