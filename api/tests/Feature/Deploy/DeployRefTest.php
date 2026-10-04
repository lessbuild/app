<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\ApiScope;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Support\GitRef;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DeployRefTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The project being deployed.
     *
     * @var Project
     */
    private Project $project;

    /**
     * Its owner.
     *
     * @var User
     */
    private User $owner;

    /**
     * Its production environment.
     *
     * @var Environment
     */
    private Environment $production;

    /**
     * The repository that deploys to production.
     *
     * @var Repository
     */
    private Repository $repository;

    /**
     * Set up a GitHub repository deploying to production on a live website.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'shop']);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'token' => 'ghp_secret']);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $this->project->id, 'provider_id' => $github->id, 'environment_id' => $this->production->id]);
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Check that a branch, tag or commit can be deployed from the web, the script resolves it on the server, and
     * refs git would misread are refused.
     *
     * @return void
     */
    public function test_a_named_version_is_deployed_and_resolved_on_the_server(): void
    {
        $base = "/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}";
        $this->actingAs($this->owner)->getJson($base)->assertOk()->assertJsonPath('canDeploy', true);
        $this->actingAs($this->owner)->postJson("{$base}/builds", ['ref' => '--upload-pack=touch /tmp/x'])->assertJsonValidationErrors('ref');
        $this->assertSame(0, Build::query()->count());

        $this->actingAs($this->owner)->postJson("{$base}/builds", ['ref' => 'refs/tags/v1.4.0'])->assertSuccessful();
        $build = Build::query()->sole();
        $this->assertSame(['v1.4.0', null], [$build->git_ref, $build->revision]);
        $script = $this->scripts->started[0]['script'];
        $this->assertStringContainsString("rev-parse --verify --quiet 'origin/v1.4.0'^{commit}", $script);
        $this->assertStringContainsString("'refs/tags/v1.4.0'^{commit}", $script);
        $this->assertStringContainsString('checkout --detach --force "$REQUESTED_COMMIT"', $script);
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/deploy/builds/{$build->id}")->assertOk()->assertSee('v1.4.0');
    }

    /**
     * Check that the API takes a ref too and returns it.
     *
     * @return void
     */
    public function test_the_api_deploys_a_ref(): void
    {
        $token = app(CreateApiToken::class)->handle($this->owner, $this->project->account, new CreateApiTokenData('ci', [ApiScope::DeployRead, ApiScope::from('deploy:write')], 30))->plainText;
        $this->withToken($token)->postJson("/api/v1/environments/{$this->production->id}/deploy", ['ref' => 'release/2026-10'])
            ->assertStatus(202)->assertJsonPath('data.ref', 'release/2026-10');
    }

    /**
     * Check which refs are accepted.
     *
     * @return void
     */
    public function test_refs_are_checked(): void
    {
        foreach (['main', 'v1.2.0', 'feature/new-checkout', '3f2a9c1', 'release+hotfix'] as $good) {
            $this->assertSame($good, GitRef::normalize($good));
        }
        $this->assertSame('main', GitRef::normalize(' refs/heads/main '));
        foreach (['', '-x', '.hidden', 'a..b', 'a b', 'a;rm', 'x.lock', 'x/', 'a@{1}', '$(id)', "a\nb", 'a//b'] as $bad) {
            $this->assertNull(GitRef::normalize($bad), $bad);
        }
    }
}
