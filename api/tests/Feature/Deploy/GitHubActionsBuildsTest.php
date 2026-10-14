<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Jobs\Deploy\AwaitGitHubActionsBuild;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class GitHubActionsBuildsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The commit every test deploys.
     *
     * @var string
     */
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

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
     * Its production environment, building on GitHub Actions.
     *
     * @var Environment
     */
    private Environment $production;

    /**
     * The repository that deploys to production, from the web/ folder.
     *
     * @var Repository
     */
    private Repository $repository;

    /**
     * The website's server.
     *
     * @var Server
     */
    private Server $server;

    /**
     * Set up a Node.js website deploying from a GitHub repository's web/ folder.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $provider = Provider::factory()->create(['account_id' => $this->project->account_id]);
        $this->server = Server::factory()->create(['provider_id' => $provider->id]);
        $website = Website::factory()->create(['server_id' => $this->server->id, 'deployment_slug' => 'shop', 'url' => 'shop.example.com']);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'token' => 'ghp_secret']);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->production->forceFill(['runtime_type' => 'node', 'runtime_version' => '22', 'start_command' => 'node .output/server/index.mjs', 'container_port' => 3000])->save();
        $this->repository = Repository::factory()->create([
            'website_id' => $website->id, 'project_id' => $this->project->id, 'provider_id' => $github->id, 'environment_id' => $this->production->id,
            'url' => 'github.com/acme/shop', 'branch' => 'main', 'deployment_root' => 'web',
        ]);
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Check choosing GitHub Actions shows the website's workflow, and a deploy waits for its run, then downloads the
     * artifact on the website's server without cloning or building there.
     *
     * @return void
     */
    public function test_a_deploy_releases_the_artifact_built_on_github_actions(): void
    {
        $settings = "/api/app/projects/{$this->project->id}/deploy/environments/{$this->production->id}";
        $this->actingAs($this->owner)->putJson("{$settings}/build-server", ['build_server_id' => 'github'])->assertSuccessful();
        $this->assertTrue($this->production->refresh()->github_actions_builds);
        $page = $this->actingAs($this->owner)->getJson($settings)->assertOk()
            ->assertJsonPath('environment.githubActionsBuilds', true)
            ->assertJsonPath('githubWorkflows.0.path', '.github/workflows/buildpusher-shop.yml')
            ->assertJsonPath('githubWorkflows.0.repository', 'acme/shop');
        $yaml = (string) $page->json('githubWorkflows.0.yaml');
        $this->assertStringContainsString("working-directory: 'web'", $yaml);
        $this->assertStringContainsString("branches: ['main']", $yaml);
        $this->assertStringContainsString("node-version: '22'", $yaml);
        $this->assertStringContainsString('tar --create --gzip --file "$RUNNER_TEMP/buildpusher.tar.gz" .output', $yaml);
        $this->assertStringContainsString('name: buildpusher', $yaml);
        $steps = Yaml::parse($yaml)['jobs']['build']['steps'];
        $this->assertSame(['actions/checkout@v4', 'actions/setup-node@v4', null, null, null, 'actions/upload-artifact@v4'], array_map(fn (array $step): ?string => $step['uses'] ?? null, $steps));

        Http::fake([
            'api.github.com/repos/acme/shop/commits/*' => Http::response(['sha' => self::SHA, 'commit' => ['message' => 'Ship it']]),
            'api.github.com/repos/acme/shop/actions/workflows/*' => Http::response(['workflow_runs' => [['id' => 77, 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/shop/actions/runs/77']]]),
            'api.github.com/repos/acme/shop/actions/runs/77/artifacts*' => Http::response(['artifacts' => [['name' => 'buildpusher', 'expired' => false, 'archive_download_url' => 'https://api.github.com/repos/acme/shop/actions/artifacts/5/zip']]]),
            'api.github.com/repos/acme/shop/actions/artifacts/5/zip' => Http::response('', 302, ['Location' => 'https://results.example.test/artifact.zip?sig=abc']),
        ]);
        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds")->assertSuccessful();

        $build = Build::query()->sole();
        $this->assertSame([Build::STATUS_RUNNING, 'release', self::SHA, 'Ship it'], [$build->status, $build->build_phase, $build->revision, $build->commit_message]);
        $this->assertCount(1, $this->scripts->started);
        $release = $this->scripts->started[0];
        $this->assertSame($this->server->id, $release['server']);
        $this->assertStringContainsString("'https://results.example.test/artifact.zip?sig=abc'", $release['script']);
        $this->assertStringContainsString("python3 -m zipfile -e '/tmp/lessbuild-artifact-{$build->id}.zip'", $release['script']);
        $this->assertStringContainsString("--directory '/var/www/shop/setup/web'", $release['script']);
        $this->assertStringContainsString(base64_encode('Built on GitHub Actions: https://github.com/acme/shop/actions/runs/77'), $release['script']);
        $this->assertStringNotContainsString('git clone', $release['script']);
        $this->assertStringNotContainsString('npm ci', $release['script']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'head_sha='.self::SHA) && $request->hasHeader('Authorization', 'Bearer ghp_secret'));
    }

    /**
     * Check a deploy keeps waiting while the run is going, and fails with the run's link when it fails.
     *
     * @return void
     */
    public function test_a_deploy_waits_for_the_run_and_fails_with_it(): void
    {
        $this->production->forceFill(['github_actions_builds' => true])->save();
        $build = $this->waitingBuild();
        Http::fakeSequence('api.github.com/repos/acme/shop/actions/workflows/*')
            ->push(['workflow_runs' => [['id' => 9, 'status' => 'in_progress', 'conclusion' => null, 'html_url' => 'https://github.com/acme/shop/actions/runs/9']]])
            ->push(['workflow_runs' => [['id' => 9, 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/shop/actions/runs/9']]]);
        Queue::fake();

        app()->call([new AwaitGitHubActionsBuild($build->id), 'handle']);
        Queue::assertPushed(AwaitGitHubActionsBuild::class, fn (AwaitGitHubActionsBuild $job): bool => $job->buildId === $build->id);
        $this->assertSame(['actions', 'Building on GitHub Actions: https://github.com/acme/shop/actions/runs/9'], [$build->refresh()->build_phase, $build->log]);

        app()->call([new AwaitGitHubActionsBuild($build->id), 'handle']);
        $this->assertSame(Build::STATUS_FAILED, $build->refresh()->status);
        $this->assertStringContainsString('https://github.com/acme/shop/actions/runs/9', (string) $build->failure_message);
        $this->assertCount(0, $this->scripts->started);
    }

    /**
     * Check redeploying a commit whose artifact GitHub has deleted runs the workflow again, keeps waiting while GitHub
     * still reports the old run, asks only once per deploy, and fails if the run still uploads none.
     *
     * @return void
     */
    public function test_an_expired_artifact_runs_the_workflow_again(): void
    {
        $this->production->forceFill(['github_actions_builds' => true])->save();
        $build = $this->waitingBuild();
        Http::fake([
            'api.github.com/repos/acme/shop/actions/workflows/*' => Http::response(['workflow_runs' => [['id' => 12, 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/shop/actions/runs/12']]]),
            // GitHub stops listing an artifact once it has deleted it.
            'api.github.com/repos/acme/shop/actions/runs/12/artifacts*' => Http::response(['artifacts' => []]),
            'api.github.com/repos/acme/shop/actions/runs/12/rerun' => Http::response('', 201),
        ]);
        Queue::fake();

        app()->call([new AwaitGitHubActionsBuild($build->id), 'handle']);
        $this->assertSame([Build::STATUS_RUNNING, 'GitHub had deleted this commit’s build, so it’s building again: https://github.com/acme/shop/actions/runs/12'], [$build->refresh()->status, $build->log]);
        Queue::assertPushed(AwaitGitHubActionsBuild::class, 1);

        // GitHub still shows the old finished run: keep waiting rather than failing or asking again.
        app()->call([new AwaitGitHubActionsBuild($build->id), 'handle']);
        $this->assertSame(Build::STATUS_RUNNING, $build->refresh()->status);
        Http::assertSentCount(5);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/actions/runs/12/rerun'));
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/rerun')));
        $this->assertCount(0, $this->scripts->started);

        // Once the re-run has had time to start, a run that still has no artifact means the workflow uploads none.
        $this->travel(3)->minutes();
        app()->call([new AwaitGitHubActionsBuild($build->id), 'handle']);
        $this->assertSame(Build::STATUS_FAILED, $build->refresh()->status);
        $this->assertStringContainsString('has no “buildpusher” artifact', (string) $build->failure_message);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/rerun')));
    }

    /**
     * Check a deploy whose workflow never runs fails after the wait with what to do, and one still waiting can be
     * cancelled with nothing to stop on a server.
     *
     * @return void
     */
    public function test_a_missing_workflow_fails_and_a_waiting_deploy_can_be_cancelled(): void
    {
        $this->production->forceFill(['github_actions_builds' => true])->save();
        Http::fake(['api.github.com/repos/acme/shop/actions/workflows/*' => Http::response(['message' => 'Not Found'], 404)]);
        Queue::fake();
        $late = $this->waitingBuild(['started_at' => now()->subMinutes(AwaitGitHubActionsBuild::START_MINUTES + 1)]);
        app()->call([new AwaitGitHubActionsBuild($late->id), 'handle']);
        $this->assertSame(Build::STATUS_FAILED, $late->refresh()->status);
        $this->assertStringContainsString('.github/workflows/buildpusher-shop.yml', (string) $late->failure_message);

        $waiting = $this->waitingBuild();
        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/builds/{$waiting->id}/cancel")->assertSuccessful();
        $this->assertSame(Build::STATUS_CANCELED, $waiting->refresh()->status);
        $this->assertSame([], $this->shell->ran);
    }

    /**
     * Check GitHub Actions only builds PHP and Node.js environments.
     *
     * @return void
     */
    public function test_only_php_and_node_environments_build_on_github_actions(): void
    {
        $this->production->forceFill(['runtime_type' => 'python'])->save();
        $this->actingAs($this->owner)->putJson("/api/app/projects/{$this->project->id}/deploy/environments/{$this->production->id}/build-server", ['build_server_id' => 'github'])
            ->assertJsonValidationErrors('build_server_id');
        $this->assertFalse($this->production->refresh()->github_actions_builds);
    }

    /**
     * Check a PHP website's workflow installs Composer packages on the website's PHP version, runs its build commands,
     * and ships the folder without node_modules, as valid YAML.
     *
     * @return void
     */
    public function test_a_php_website_workflow_mirrors_the_server_build(): void
    {
        $this->production->forceFill(['runtime_type' => 'php', 'runtime_version' => null, 'start_command' => null, 'build_command' => null])->save();
        $this->repository->forceFill(['deployment_root' => null, 'build_commands' => "php artisan icons:cache\nnpm run docs"])->save();
        $yaml = (string) $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/deploy/environments/{$this->production->id}")->assertOk()->json('githubWorkflows.0.yaml');
        $workflow = Yaml::parse($yaml);
        // Each step by its name, or by the action it uses.
        $steps = [];
        foreach ((array) $workflow['jobs']['build']['steps'] as $step) {
            $steps[(string) ($step['name'] ?? $step['uses'])] = (array) $step;
        }
        $this->assertSame('.', $workflow['jobs']['build']['defaults']['run']['working-directory']);
        $this->assertSame($this->repository->website->phpVersion(), ((array) ($steps['shivammathur/setup-php@v2']['with'] ?? []))['php-version'] ?? null);
        $this->assertStringContainsString('composer install --no-interaction --no-dev', (string) ($steps['Install PHP dependencies']['run'] ?? ''));
        $this->assertSame("php artisan icons:cache\nnpm run docs", trim((string) ($steps['Build commands']['run'] ?? '')));
        $this->assertStringContainsString('--exclude=./node_modules', (string) ($steps['Pack the release']['run'] ?? ''));
    }

    /**
     * Make a running build that is waiting on GitHub Actions for the test commit.
     *
     * @param  array<string, mixed>  $attributes
     * @return Build
     */
    private function waitingBuild(array $attributes = []): Build
    {
        $build = Build::factory()->create([
            'repository_id' => $this->repository->id, 'website_id' => $this->repository->website_id, 'environment_id' => $this->production->id,
            'status' => Build::STATUS_RUNNING, 'build_phase' => 'actions', 'revision' => self::SHA, 'started_at' => now(), 'last_heartbeat_at' => now(),
            'release_name' => 'r1', 'release_path' => '/var/www/shop/releases/r1', ...$attributes,
        ]);

        return $build;
    }
}
