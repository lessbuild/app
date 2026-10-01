<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Enums\ServerType;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\StorageBucket;
use App\Models\User;
use App\Models\Website;
use App\Services\Deploy\RepositoryDeploymentPlan;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class BuildServersTest extends TestCase
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
     * The website's own server.
     *
     * @var Server
     */
    private Server $webServer;

    /**
     * The worker server builds can run on.
     *
     * @var Server
     */
    private Server $builder;

    /**
     * The project's storage bucket.
     *
     * @var StorageBucket
     */
    private StorageBucket $bucket;

    /**
     * Set up a repository deploying to a live website, a worker server and a storage bucket.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        Http::fake(['storage.example.test/*' => Http::response('', 204)]);
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $provider = Provider::factory()->create(['account_id' => $this->project->account_id]);
        $this->webServer = Server::factory()->create(['provider_id' => $provider->id, 'name' => 'web-1']);
        $this->builder = Server::factory()->create(['provider_id' => $provider->id, 'name' => 'builder-1', 'type' => ServerType::Worker]);
        $website = Website::factory()->create(['server_id' => $this->webServer->id, 'deployment_slug' => 'shop']);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'token' => 'ghp_secret']);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $this->project->id, 'provider_id' => $github->id, 'environment_id' => $this->production->id]);
        $this->bucket = (new StorageBucket)->forceFill(['project_id' => $this->project->id, 'name' => 'Builds', 'storage_provider' => 's3_compatible', 'endpoint' => 'https://storage.example.test', 'region' => 'auto', 'bucket' => 'shop-builds', 'access_key' => 'AKBUILD', 'secret_key' => 'build-secret']);
        $this->bucket->save();
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Check a deploy builds on the build server, uploads the release to the bucket with a presigned link, and the
     * website's server then downloads and releases it, carrying the build log; the artifact is deleted afterwards.
     *
     * @return void
     */
    public function test_a_deploy_builds_on_the_build_server_and_releases_on_the_website_server(): void
    {
        $settings = "/projects/{$this->project->id}/deploy/environments/{$this->production->id}";
        $this->actingAs($this->owner)->get("{$settings}?tab=settings")->assertOk()->assertSee('Build server')->assertSee('builder-1')->assertSee('Builds');
        $this->actingAs($this->owner)->put("{$settings}/build-server", ['build_server_id' => $this->builder->id])->assertSessionHasErrors('build_server_id');
        $this->actingAs($this->owner)->put("{$settings}/build-server", ['build_server_id' => $this->builder->id, 'artifact_bucket_id' => $this->bucket->id])->assertSessionHasNoErrors();
        $this->assertSame([$this->builder->id, $this->bucket->id], [$this->production->refresh()->build_server_id, $this->production->artifact_bucket_id]);

        $this->actingAs($this->owner)->post("/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds")->assertRedirect();
        $build = Build::query()->sole();
        $this->assertSame([$this->builder->id, 'build', 'buildpusher-builds/shop/'.$build->id.'.tar.gz'], [$build->build_server_id, $build->build_phase, $build->artifact_key]);
        $buildPart = $this->scripts->started[0];
        $this->assertSame($this->builder->id, $buildPart['server']);
        $this->assertStringContainsString('git clone', $buildPart['script']);
        $this->assertStringContainsString("--upload-file '/tmp/lessbuild-artifact-{$build->id}.tar.gz' 'https://storage.example.test/shop-builds/buildpusher-builds/shop/{$build->id}.tar.gz?X-Amz-Algorithm=AWS4-HMAC-SHA256", $buildPart['script']);
        $this->assertStringContainsString('/deployment/callback/artifact', $buildPart['script']);
        $this->assertStringNotContainsString('/var/www/shop/.env', $buildPart['script']);
        $this->assertStringNotContainsString('build-secret', $buildPart['script']);
        $this->assertStringNotContainsString('ActivateRelease', $buildPart['script']);

        $this->post(ProvisioningCallbackUrl::buildLog($build), ['log' => 'composer install done'])->assertNoContent();
        $this->post(ProvisioningCallbackUrl::buildArtifact($build), ['ready' => 1])->assertNoContent();
        $this->post(ProvisioningCallbackUrl::buildArtifact($build), ['ready' => 1])->assertNoContent();
        $this->assertCount(2, $this->scripts->started);
        $releasePart = $this->scripts->started[1];
        $this->assertSame($this->webServer->id, $releasePart['server']);
        $this->assertStringContainsString("tar --extract --gzip --file '/tmp/lessbuild-artifact-{$build->id}.tar.gz'", $releasePart['script']);
        $this->assertStringContainsString(base64_encode('composer install done'), $releasePart['script']);
        $this->assertStringContainsString('/var/www/shop/.env', $releasePart['script']);
        $this->assertStringNotContainsString('git clone', $releasePart['script']);
        $this->assertSame('release', $build->refresh()->build_phase);

        $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => app(RepositoryDeploymentPlan::class)->finalStage()])->assertNoContent();
        $this->assertSame(Build::STATUS_SUCCEEDED, $build->refresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), "/shop-builds/buildpusher-builds/shop/{$build->id}.tar.gz"));
    }

    /**
     * Check Docker builds, an inactive build server and other accounts' servers fall back to building on the website's
     * server, and the setting only takes the account's app or worker servers.
     *
     * @return void
     */
    public function test_builds_stay_on_the_website_server_when_a_build_server_cannot_be_used(): void
    {
        $settings = "/projects/{$this->project->id}/deploy/environments/{$this->production->id}";
        $database = Server::factory()->create(['provider_id' => $this->webServer->provider_id, 'type' => ServerType::Database]);
        $foreign = Server::factory()->create(['type' => ServerType::Worker]);
        $this->actingAs($this->owner)->put("{$settings}/build-server", ['build_server_id' => $database->id, 'artifact_bucket_id' => $this->bucket->id])->assertSessionHasErrors('build_server_id');
        $this->actingAs($this->owner)->put("{$settings}/build-server", ['build_server_id' => $foreign->id, 'artifact_bucket_id' => $this->bucket->id])->assertNotFound();

        $this->production->forceFill(['build_server_id' => $this->builder->id, 'artifact_bucket_id' => $this->bucket->id, 'runtime_type' => 'docker'])->save();
        $this->actingAs($this->owner)->post("/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds")->assertRedirect();
        $this->assertSame([$this->webServer->id, null], [$this->scripts->started[0]['server'], Build::query()->sole()->build_phase]);
    }

    /**
     * Check cancelling a deploy while it builds stops the script on the build server.
     *
     * @return void
     */
    public function test_cancelling_while_building_stops_the_build_server_script(): void
    {
        $this->production->forceFill(['build_server_id' => $this->builder->id, 'artifact_bucket_id' => $this->bucket->id])->save();
        $this->actingAs($this->owner)->post("/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds")->assertRedirect();
        $build = Build::query()->sole();

        $this->actingAs($this->owner)->post("/projects/{$this->project->id}/deploy/builds/{$build->id}/cancel")->assertRedirect();

        $this->assertSame($this->builder->id, collect($this->shell->ran)->last()['server'] ?? null);
        $this->assertSame(Build::STATUS_CANCELED, $build->refresh()->status);
    }
}
