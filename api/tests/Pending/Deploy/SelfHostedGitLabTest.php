<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Contracts\Monitoring\DnsResolver;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use App\Services\Infrastructure\ProviderConnectionTester;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class SelfHostedGitLabTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that a GitLab provider can point at a self-hosted GitLab: its address is normalised, repositories on that
     * host are accepted (and gitlab.com ones refused), and deploys clone from it with the token.
     *
     * @return void
     */
    public function test_repositories_deploy_from_a_self_hosted_gitlab(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);

        $fields = ['name' => 'Company GitLab', 'type' => 'gitlab', 'token' => 'glpat-secret'];
        $this->actingAs($owner)->post('/account/providers', [...$fields, 'base_url' => 'http://gitlab.acme.test'])->assertSessionHasErrors('base_url');
        $this->actingAs($owner)->post('/account/providers', [...$fields, 'base_url' => 'https://gitlab.acme.test:8443'])->assertSessionHasErrors('base_url');
        $this->actingAs($owner)->post('/account/providers', [...$fields, 'base_url' => 'GitLab.Acme.test/'])->assertSessionHasNoErrors();
        $gitlab = Provider::query()->where('name', 'Company GitLab')->sole();
        $this->assertSame(['https://gitlab.acme.test', 'gitlab.acme.test'], [$gitlab->base_url, $gitlab->repositoryHost()]);

        $website = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id])->id, 'deployment_slug' => 'shop']);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $repository = ['name' => 'shop', 'provider_id' => $gitlab->id, 'branch' => 'main', 'website_id' => $website->id, 'environment_id' => $production->id];
        $this->actingAs($owner)->post("/projects/{$project->id}/deploy/repositories", [...$repository, 'url' => 'https://gitlab.com/acme/shop'])->assertSessionHasErrors('url');
        $this->actingAs($owner)->post("/projects/{$project->id}/deploy/repositories", [...$repository, 'url' => 'https://gitlab.acme.test/team/shop.git'])->assertSessionHasNoErrors();
        $saved = Repository::query()->sole();
        $this->assertSame('gitlab.acme.test/team/shop', $saved->url);

        $this->actingAs($owner)->post("/projects/{$project->id}/deploy/repositories/{$saved->id}/builds")->assertRedirect();
        $this->assertSame(1, Build::query()->count());
        $script = $this->scripts->started[0]['script'];
        $this->assertStringContainsString(base64_encode("machine gitlab.acme.test\nlogin oauth2\npassword glpat-secret\n"), $script);
        $this->assertStringContainsString("git clone -- 'https://gitlab.acme.test/team/shop'", $script);
    }

    /**
     * Check that the connection test only calls a self-hosted GitLab on a public address, and uses its API.
     *
     * @return void
     */
    public function test_the_connection_test_only_calls_public_addresses(): void
    {
        $provider = Provider::factory()->type(ProviderType::GitLab)->create(['base_url' => 'https://gitlab.acme.test', 'token' => 'glpat-secret']);
        $dns = $this->mock(DnsResolver::class);
        $dns->shouldReceive('addresses')->with('gitlab.acme.test')->andReturn(['10.0.0.5'])->once();
        Http::fake(['*' => Http::response(['id' => 1])]);
        $this->assertFalse(app(ProviderConnectionTester::class)->test($provider)['successful']);
        Http::assertNothingSent();

        $dns->shouldReceive('addresses')->with('gitlab.acme.test')->andReturn(['93.184.216.34']);
        $this->assertTrue(app(ProviderConnectionTester::class)->test($provider)['successful']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://gitlab.acme.test/api/v4/user' && $request->header('PRIVATE-TOKEN') === ['glpat-secret']);
    }
}
