<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class RepositoryStackTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Repository $repository;

    private string $base;

    /**
     * A GitHub repository holding a Laravel API in api/ and a Nuxt site in web/, connected for the API.
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
        $website = Website::factory()->create(['account_id' => $this->project->account_id, 'server_id' => $server->id, 'deployment_slug' => 'api', 'url' => 'api.example.com']);
        $production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $production->forceFill(['runtime_type' => 'php'])->save();
        $this->repository = Repository::factory()->create([
            'project_id' => $this->project->id, 'website_id' => $website->id, 'environment_id' => $production->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'deployment_root' => 'api',
            'provider_id' => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'token' => 'ghp_test'])->id,
        ]);
        $this->base = "/api/app/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}";
        $files = [
            'api/composer.json' => json_encode(['require' => ['php' => '^8.4', 'laravel/framework' => '^12.0', 'predis/predis' => '^2.0']]),
            'api/.env.example' => "DB_CONNECTION=mysql\n",
            'api/app/Jobs/SendDigest.php' => '<?php',
            'api/routes/console.php' => "<?php\nSchedule::command('digest:send')->daily();",
            'web/package.json' => json_encode(['scripts' => ['build' => 'nuxt build'], 'dependencies' => ['nuxt' => '^4.1.0']]),
        ];
        Http::fake([
            'api.github.com/repos/acme/shop/git/trees/main*' => Http::response(['tree' => array_map(fn (string $path): array => ['path' => $path, 'type' => 'blob'], [
                'api', 'api/app', 'api/app/Jobs', 'api/routes', 'web', ...array_keys($files),
            ])]),
            'api.github.com/repos/acme/shop/contents/*' => function ($request) use ($files) {
                $path = (string) preg_replace('#^.*/contents/([^?]+).*$#', '$1', rawurldecode($request->url()));

                return isset($files[$path]) ? Http::response((string) $files[$path]) : Http::response('', 404);
            },
        ]);
    }

    /**
     * Look again reads the branch through GitHub; the page lists both apps, marks the API as the one deployed, and
     * suggests what the environment is missing; each suggestion adds it once.
     *
     * @return void
     */
    public function test_the_repository_page_shows_its_apps_and_what_they_need(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/inspect")->assertOk();
        $stack = $this->actingAs($this->owner)->getJson($this->base)->assertOk()->json('stack');

        $this->assertSame(['Laravel 12', 'Nuxt 4'], array_column($stack['apps'], 'label'));
        $this->assertSame('api', $stack['deploys']);
        $this->assertSame(['queue', 'scheduler', 'redis', 'database'], array_column($stack['suggestions'], 'key'));

        foreach (['queue', 'scheduler', 'redis', 'database'] as $key) {
            $this->actingAs($this->owner)->postJson("{$this->base}/suggestions", ['key' => $key])->assertOk();
        }
        $environment = $this->repository->environment()->firstOrFail();
        $this->assertSame(['queue' => 'php artisan queue:work --tries=3 --max-time=3600', 'scheduler' => 'php artisan schedule:work'], EnvironmentProcess::query()->where('environment_id', $environment->id)->pluck('command', 'name')->all());
        $this->assertEqualsCanonicalizing(['redis' => 'redis', 'database' => 'mysql'], EnvironmentResource::query()->where('environment_id', $environment->id)->pluck('type', 'name')->all());
        $this->assertSame([], $this->actingAs($this->owner)->getJson($this->base)->json('stack.suggestions'));
        $this->actingAs($this->owner)->postJson("{$this->base}/suggestions", ['key' => 'queue'])->assertJsonValidationErrors('key');
    }

    /**
     * A repository GitHub won't let Buildpusher read says why, and a Node app on a PHP environment, or a repository
     * whose apps are all in folders while it builds the top level, gets told how to fix it.
     *
     * @return void
     */
    public function test_problems_are_explained(): void
    {
        $this->repository->forceFill(['deployment_root' => null])->save();
        $this->actingAs($this->owner)->postJson("{$this->base}/inspect")->assertOk();
        $this->assertSame('subdirectory', $this->actingAs($this->owner)->getJson($this->base)->json('stack.suggestions.0.key'));

        $this->repository->forceFill(['deployment_root' => 'web'])->save();
        $this->assertSame('runtime', $this->actingAs($this->owner)->getJson($this->base)->json('stack.suggestions.0.key'));
        $this->assertSame('Nuxt 4 runs on Node.js, but the environment is set to PHP', $this->actingAs($this->owner)->getJson($this->base)->json('stack.suggestions.0.title'));

        Http::fake(['api.github.com/repos/acme/private/*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->repository->forceFill(['url' => 'github.com/acme/private'])->save();
        $this->actingAs($this->owner)->postJson("{$this->base}/inspect")->assertJsonValidationErrors('repository');
        $this->assertNotNull($this->repository->refresh()->stack_error);
    }
}
