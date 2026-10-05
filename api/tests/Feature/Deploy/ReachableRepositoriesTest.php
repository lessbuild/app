<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ReachableRepositoriesTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Connecting a repository lists what each Git provider can reach (GitHub, GitLab.com, Bitbucket), says so when a
     * provider refuses or can't be listed, and never lists another account's provider.
     */
    public function test_git_providers_list_the_repositories_they_can_reach(): void
    {
        $project = Project::factory()->withServices(['deploy'])->create();
        $owner = $this->ownerOf($project);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        $gitlab = Provider::factory()->type(ProviderType::GitLab)->create(['account_id' => $project->account_id]);
        $bitbucket = Provider::factory()->type(ProviderType::Bitbucket)->create(['account_id' => $project->account_id]);
        $selfHosted = Provider::factory()->type(ProviderType::GitLab)->create(['account_id' => $project->account_id, 'base_url' => 'https://git.example.com']);
        $elsewhere = Provider::factory()->type(ProviderType::GitHub)->create();
        Http::fake([
            'api.github.com/user/repos*' => Http::response([['full_name' => 'acme/shop', 'private' => true, 'default_branch' => 'main', 'pushed_at' => '2026-10-01T10:00:00Z']]),
            'gitlab.com/api/v4/projects*' => Http::response([['path_with_namespace' => 'acme/api', 'visibility' => 'public', 'default_branch' => 'trunk']]),
            'api.bitbucket.org/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);
        $url = "/api/app/projects/{$project->id}/deploy/repositories/reachable";
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/deploy/repositories/create")->assertOk()
            ->assertJsonPath('overview.project.id', $project->id)->assertJsonStructure(['options' => ['providers', 'websites', 'environments']]);

        $this->actingAs($owner)->getJson("{$url}?provider={$github->id}")->assertOk()
            ->assertJsonPath('repositories.0.name', 'acme/shop')->assertJsonPath('repositories.0.url', 'github.com/acme/shop')
            ->assertJsonPath('repositories.0.private', true)->assertJsonPath('error', null);
        $this->actingAs($owner)->getJson("{$url}?provider={$gitlab->id}")->assertOk()
            ->assertJsonPath('repositories.0.url', 'gitlab.com/acme/api')->assertJsonPath('repositories.0.branch', 'trunk')->assertJsonPath('repositories.0.private', false);
        $this->assertNotNull($this->actingAs($owner)->getJson("{$url}?provider={$bitbucket->id}")->assertOk()->assertJsonCount(0, 'repositories')->json('error'));
        $this->assertNotNull($this->actingAs($owner)->getJson("{$url}?provider={$selfHosted->id}")->assertOk()->json('error'));
        $this->actingAs($owner)->getJson("{$url}?provider={$elsewhere->id}")->assertNotFound();
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'git.example.com'));
    }
}
