<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class BranchesTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Connecting a repository lists its branches from GitHub, GitLab.com or Bitbucket, so the branch can be picked;
     * an address on another host, a self-hosted GitLab, a refusal or another account's provider lists none.
     */
    public function test_branches_are_listed_to_pick_one_when_connecting_a_repository(): void
    {
        $project = Project::factory()->withServices(['deploy'])->create();
        $owner = $this->ownerOf($project);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        $gitlab = Provider::factory()->type(ProviderType::GitLab)->create(['account_id' => $project->account_id]);
        $bitbucket = Provider::factory()->type(ProviderType::Bitbucket)->create(['account_id' => $project->account_id]);
        $selfHosted = Provider::factory()->type(ProviderType::GitLab)->create(['account_id' => $project->account_id, 'base_url' => 'https://git.example.com']);
        $elsewhere = Provider::factory()->type(ProviderType::GitHub)->create();
        Http::fake([
            'api.github.com/repos/somecv/app/branches*' => Http::response([['name' => 'development'], ['name' => 'v3']]),
            'gitlab.com/api/v4/projects/acme%2Fapi/repository/branches*' => Http::response([['name' => 'trunk']]),
            'api.bitbucket.org/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);
        $url = "/api/app/projects/{$project->id}/deploy/repositories/branches";

        $this->actingAs($owner)->getJson("{$url}?provider={$github->id}&url=".urlencode('https://github.com/somecv/app.git'))->assertOk()
            ->assertExactJson(['branches' => ['development', 'v3'], 'error' => null]);
        $this->actingAs($owner)->getJson("{$url}?provider={$gitlab->id}&url=gitlab.com/acme/api")->assertOk()->assertJsonPath('branches', ['trunk']);
        $this->assertNotNull($this->actingAs($owner)->getJson("{$url}?provider={$bitbucket->id}&url=bitbucket.org/acme/shop")->assertOk()->assertJsonCount(0, 'branches')->json('error'));
        $this->assertNotNull($this->actingAs($owner)->getJson("{$url}?provider={$github->id}&url=gitlab.com/acme/api")->assertOk()->assertJsonCount(0, 'branches')->json('error'));
        $this->assertNotNull($this->actingAs($owner)->getJson("{$url}?provider={$selfHosted->id}&url=gitlab.com/acme/api")->assertOk()->json('error'));
        $this->actingAs($owner)->getJson("{$url}?provider={$elsewhere->id}&url=github.com/somecv/app")->assertNotFound();
        Http::assertSentCount(3);
    }

    /**
     * A connected repository's page lists its branches through its own provider, for deploying a version or booking a
     * deploy; it isn't listed for people outside the project or through another project's address.
     */
    public function test_a_connected_repositorys_branches_are_listed_for_deploying_a_version(): void
    {
        $project = Project::factory()->withServices(['deploy'])->create();
        $website = Website::factory()->create(['account_id' => $project->account_id]);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        $repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => $github->id, 'url' => 'github.com/somecv/app', 'branch' => 'development']);
        $other = Repository::factory()->create(['website_id' => $website->id, 'provider_id' => $github->id]);
        Http::fake(['api.github.com/repos/somecv/app/branches*' => Http::response([['name' => 'development'], ['name' => 'v3']])]);

        $this->actingAs($this->ownerOf($project))->getJson("/api/app/projects/{$project->id}/deploy/repositories/{$repository->id}/branches")->assertOk()
            ->assertExactJson(['branches' => ['development', 'v3'], 'error' => null]);
        $this->actingAs($this->ownerOf($project))->getJson("/api/app/projects/{$project->id}/deploy/repositories/{$other->id}/branches")->assertNotFound();
        $this->actingAs($this->ownerOf(Project::factory()->withServices(['deploy'])->create()))
            ->getJson("/api/app/projects/{$project->id}/deploy/repositories/{$repository->id}/branches")->assertNotFound();
    }
}
