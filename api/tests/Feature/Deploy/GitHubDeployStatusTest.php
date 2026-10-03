<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Actions\Deploy\FinishBuild;
use App\Actions\Deploy\RecordBuildRevision;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class GitHubDeployStatusTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private const REVISION = '0123456789abcdef0123456789abcdef01234567';

    /**
     * Check that a GitHub App repository's deploys show on the commit as a check run per environment, from deploying
     * to live or failed, and that other repositories don't call GitHub.
     *
     * @return void
     */
    public function test_deploys_show_on_their_github_commit(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $private);
        config(['github-app.id' => '12345', 'github-app.slug' => 'buildpusher', 'github-app.webhook_secret' => 'secret', 'github-app.private_key' => $private]);
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_installation_token'], 201),
            'api.github.com/repos/acme/shop/check-runs' => Http::response(['id' => 1], 201),
        ]);
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id, 'credential_type' => 'app', 'external_id' => '77']);
        $website = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id])->id]);
        $repository = Repository::factory()->create(['url' => 'github.com/acme/shop', 'website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => $github->id, 'environment_id' => $production->id]);

        // A branch deploy learns its commit once the server checks it out; then it's deploying, then live.
        $build = Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'status' => Build::STATUS_RUNNING, 'revision' => null]);
        app(RecordBuildRevision::class)->handle($build, self::REVISION, 'Fix checkout');
        app(FinishBuild::class)->handle($build->refresh(), Build::STATUS_SUCCEEDED);

        $checks = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])->filter(fn (Request $request): bool => str_ends_with($request->url(), '/check-runs'))->values();
        $this->assertCount(2, $checks);
        /** @var array{0: Request, 1: Request} $checks */
        $checks = $checks->all();
        $this->assertSame([config('app.name').' deploy: Production', self::REVISION, 'in_progress'], [$checks[0]['name'], $checks[0]['head_sha'], $checks[0]['status']]);
        $this->assertSame(['completed', 'success'], [$checks[1]['status'], $checks[1]['conclusion']]);
        $this->assertStringEndsWith("/deploy/builds/{$build->id}", (string) $checks[1]['details_url']);

        $failed = Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'status' => Build::STATUS_RUNNING, 'revision' => self::REVISION]);
        app(FinishBuild::class)->handle($failed, Build::STATUS_FAILED, 'Tests failed');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/check-runs') && ($request['conclusion'] ?? null) === 'failure');

        // Repositories connected with a token, not the App, are left alone.
        $github->forceFill(['credential_type' => 'token'])->save();
        $count = count(Http::recorded());
        $other = Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'status' => Build::STATUS_RUNNING, 'revision' => self::REVISION]);
        app(FinishBuild::class)->handle($other, Build::STATUS_SUCCEEDED);
        $this->assertCount($count, Http::recorded());
    }
}
