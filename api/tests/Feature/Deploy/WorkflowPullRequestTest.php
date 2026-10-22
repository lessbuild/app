<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Deploy\GitHubActionsBuilds;
use App\Services\Deploy\GitHubWorkflowPullRequests;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class WorkflowPullRequestTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Environment $environment;

    private Repository $repository;

    /**
     * A Nuxt site in web/ of github.com/acme/shop, built on GitHub Actions.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $this->withoutMiddleware(RequirePassword::class);
        $server = Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $website = Website::factory()->create(['account_id' => $this->project->account_id, 'server_id' => $server->id, 'deployment_slug' => 'web', 'url' => 'example.com']);
        $this->environment = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->environment->forceFill(['runtime_type' => 'node', 'start_command' => 'node .output/server/index.mjs', 'container_port' => 3000, 'github_actions_builds' => true])->save();
        $this->repository = Repository::factory()->create([
            'project_id' => $this->project->id, 'website_id' => $website->id, 'environment_id' => $this->environment->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'deployment_root' => 'web',
            'provider_id' => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'token' => 'ghp_test'])->id,
        ]);
    }

    /**
     * The workflow on the branch is missing, out of date or current, compared with the one Buildpusher writes.
     *
     * @return void
     */
    public function test_the_workflow_status_is_read_from_the_branch(): void
    {
        $yaml = app(GitHubActionsBuilds::class)->workflow($this->repository);
        $workflows = app(GitHubWorkflowPullRequests::class);
        Http::fakeSequence('api.github.com/repos/acme/shop/contents/*')->push('', 404)->push("name: old\n")->push($yaml."\n");

        $this->assertSame('missing', $workflows->status($this->repository, true));
        $this->assertSame('outdated', $workflows->status($this->repository, true));
        $this->assertSame('current', $workflows->status($this->repository, true));
        $this->assertSame('current', $workflows->status($this->repository), 'Cached, without asking GitHub again.');
    }

    /**
     * Open a pull request makes a branch from main, commits the workflow there and opens the pull request; the
     * environment page then links it. Without permission, the page says which permission is missing.
     *
     * @return void
     */
    public function test_a_pull_request_adds_the_workflow(): void
    {
        $path = app(GitHubActionsBuilds::class)->workflowPath($this->repository);
        Http::fake([
            'api.github.com/repos/acme/shop/git/ref/heads/main' => Http::response(['object' => ['sha' => 'abc123']]),
            'api.github.com/repos/acme/shop/git/ref/heads/buildpusher/workflow-web' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/acme/shop/git/refs' => Http::response(['ref' => 'refs/heads/buildpusher/workflow-web'], 201),
            'api.github.com/repos/acme/shop/contents/*' => fn (Request $request) => $request->method() === 'PUT' ? Http::response(['content' => []], 201) : Http::response('', 404),
            'api.github.com/repos/acme/shop/pulls*' => fn (Request $request) => $request->method() === 'GET' ? Http::response([]) : Http::response(['html_url' => 'https://github.com/acme/shop/pull/7'], 201),
        ]);
        $base = "/api/app/projects/{$this->project->id}/deploy/environments/{$this->environment->id}";

        $this->actingAs($this->owner)->postJson("{$base}/workflow-pull-requests", ['repository_id' => $this->repository->id])->assertOk()->assertJsonPath('message', 'Pull request ready: https://github.com/acme/shop/pull/7');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/git/refs') && $request['sha'] === 'abc123');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_contains(rawurldecode($request->url()), $path)
            && $request['branch'] === 'buildpusher/workflow-web' && base64_decode((string) $request['content']) === app(GitHubActionsBuilds::class)->workflow($this->repository));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls') && $request['base'] === 'main' && $request['head'] === 'buildpusher/workflow-web');
        $this->assertSame('https://github.com/acme/shop/pull/7', $this->repository->refresh()->workflow_pull_request_url);
        $this->actingAs($this->owner)->getJson($base)->assertOk()
            ->assertJsonPath('githubWorkflows.0.status', 'missing')->assertJsonPath('githubWorkflows.0.pullRequest', 'https://github.com/acme/shop/pull/7');
    }

    /**
     * GitHub turning the request down is explained, with the permission Buildpusher needs.
     *
     * @return void
     */
    public function test_missing_permission_is_explained(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Resource not accessible by integration'], 403)]);
        $base = "/api/app/projects/{$this->project->id}/deploy/environments/{$this->environment->id}";

        $this->actingAs($this->owner)->postJson("{$base}/workflow-pull-requests", ['repository_id' => $this->repository->id])
            ->assertJsonValidationErrors(['repository_id' => 'permission to write contents and pull requests']);
    }
}
