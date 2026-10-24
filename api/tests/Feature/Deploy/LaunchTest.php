<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Enums\ServerType;
use App\Jobs\Deploy\RunLaunch;
use App\Models\Build;
use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\Launch;
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
use stdClass;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class LaunchTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Server $server;

    private Provider $github;

    private string $base;

    /**
     * A project with an active app server, and a GitHub repository holding a Laravel API in api/ (queued jobs, a
     * schedule, Stripe keys to fill in) and a Nuxt site in web/.
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
        $this->onTier($this->project, 'infrastructure', 'pro');
        $this->withoutMiddleware(RequirePassword::class);
        $this->server = Server::factory()->create([
            'account_id' => $this->project->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id,
            'type' => ServerType::App, 'provisioning_status' => Server::STATUS_ACTIVE, 'mysql_root_password' => 'root-secret', 'public_ip' => '203.0.113.10', 'name' => 'shop',
        ]);
        $this->github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'token' => 'ghp_test']);
        $this->base = "/api/app/projects/{$this->project->id}/deploy";
        $files = [
            'api/composer.json' => (string) json_encode(['require' => ['php' => '^8.4', 'laravel/framework' => '^12.0']]),
            'api/.env.example' => "APP_KEY=\nDB_CONNECTION=sqlite\nSTRIPE_KEY=\nSTRIPE_SECRET=\nMAIL_MAILER=log\n",
            'api/app/Jobs/SendDigest.php' => '<?php',
            'api/routes/console.php' => "<?php\nSchedule::command('digest:send')->daily();",
            'web/package.json' => (string) json_encode(['scripts' => ['build' => 'nuxt build'], 'dependencies' => ['nuxt' => '^4.1.0']]),
        ];
        Http::fake([
            'api.github.com/repos/acme/shop/git/trees/main*' => Http::response(['tree' => array_map(fn (string $path): array => ['path' => $path], ['api', 'api/app', 'api/app/Jobs', 'api/routes', 'web', ...array_keys($files)])]),
            'api.github.com/repos/acme/shop/contents/*' => function ($request) use ($files) {
                $path = (string) preg_replace('#^.*/contents/([^?]+).*$#', '$1', rawurldecode($request->url()));
                if (str_starts_with($path, '.github/')) {
                    return null; // Left to the workflow tests' own fakes.
                }

                return isset($files[$path]) ? Http::response($files[$path]) : Http::response('', 404);
            },
        ]);
    }

    /**
     * A website already added under Infrastructure, ready for an app to launch onto: in the project, on the server,
     * with no repository deploying to it yet.
     *
     * @param  string  $url
     * @param  string  $slug
     * @return Website
     */
    private function bareWebsite(string $url, string $slug): Website
    {
        return Website::factory()->create([
            'account_id' => $this->project->account_id, 'server_id' => $this->server->id, 'environment_id' => null,
            'url' => $url, 'deployment_slug' => $slug, 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
    }

    /**
     * The plan proposes the API on api.example.com and the site on example.com, each with its environment; launching
     * sends each app onto a website you already added, sets up the environments, deploys the API before the site, and
     * the page then shows the checklist.
     *
     * @return void
     */
    public function test_a_repository_launches_in_one_go(): void
    {
        $api = $this->bareWebsite('api.example.com', 'api');
        $web = $this->bareWebsite('www.example.com', 'web');
        $this->actingAs($this->owner)->getJson("{$this->base}/launch")->assertOk()->assertJsonPath('sources.0.value', (string) $this->github->id)->assertJsonCount(2, 'websites');
        $plan = $this->actingAs($this->owner)->postJson("{$this->base}/launch/plan", ['provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'example.com'])->assertOk()->json('plan');

        [$apiPlan, $webPlan] = $plan['apps'];
        $this->assertSame(['Laravel 12', 'api.example.com', 'Production', true, true, true, ['STRIPE_KEY', 'STRIPE_SECRET'], ['api/**']], [$apiPlan['label'], $apiPlan['domain'], $apiPlan['environment'], $apiPlan['mysql'], $apiPlan['queue'], $apiPlan['scheduler'], $apiPlan['secrets'], $apiPlan['includePaths']]);
        $this->assertSame(['Nuxt 4', 'example.com', 'Production-web', false], [$webPlan['label'], $webPlan['domain'], $webPlan['environment'], $webPlan['mysql']]);
        $this->assertSame([$this->server->id], array_column($plan['servers'], 'id'));

        Queue::fake();
        $this->actingAs($this->owner)->postJson("{$this->base}/launches", [
            'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'example.com',
            'server' => ['mode' => 'existing', 'server_id' => $this->server->id],
            'apps' => [['website_id' => $api->id, 'secretValues' => ['STRIPE_SECRET' => 'sk_live_1', 'NOT_ASKED' => 'x']], ['website_id' => $web->id]],
        ])->assertOk();
        $launch = Launch::query()->sole();
        Queue::assertPushed(RunLaunch::class);

        // The websites already exist and are active, so the launch goes straight on to wiring and deploying them.
        $run = fn () => (new RunLaunch($launch->id))->handle();
        $run();
        $this->assertSame(['waiting', 'deploys'], [$launch->refresh()->status, $launch->step]);
        $this->assertSame(2, Website::query()->whereIn('url', ['api.example.com', 'www.example.com'])->count(), 'No websites were created; the chosen ones are used.');
        $environments = Environment::query()->where('project_id', $this->project->id)->get()->keyBy('name')->all();
        $production = $environments['Production'];
        $this->assertSame(['php', 'node'], [$production->runtime_type, $environments['Production-web']->runtime_type]);
        $this->assertSame('node .output/server/index.mjs', $environments['Production-web']->start_command);
        $values = EnvironmentVariable::query()->where('environment_id', $production->id)->pluck('value', 'key')->all();
        $this->assertSame(['production', 'false', 'https://api.example.com', 'warning', 'file', 'sk_live_1'], [$values['APP_ENV'], $values['APP_DEBUG'], $values['APP_URL'], $values['LOG_LEVEL'], $values['SESSION_DRIVER'], $values['STRIPE_SECRET']]);
        $this->assertStringStartsWith('base64:', $values['APP_KEY']);
        $this->assertArrayNotHasKey('NOT_ASKED', $values, 'Only the secrets it asked for are kept.');
        $this->assertSame(['queue', 'scheduler'], EnvironmentProcess::query()->where('environment_id', $production->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame('api', data_get(EnvironmentResource::query()->where('environment_id', $production->id)->sole()->configuration, 'variables.DB_DATABASE'));
        $repositories = Repository::query()->where('project_id', $this->project->id)->get()->keyBy('deployment_root')->all();
        $this->assertSame([['api/**'], true], [$repositories['api']->auto_deploy_include_paths, $repositories['api']->build_cache_enabled]);
        $this->assertSame(['web/**'], $repositories['web']->auto_deploy_include_paths);

        $builds = Build::query()->get();
        $this->assertSame([$repositories['api']->id], $builds->pluck('repository_id')->all(), 'The API deploys first, alone.');
        Build::query()->update(['status' => Build::STATUS_SUCCEEDED]);
        $run();
        $this->assertSame([$repositories['api']->id, $repositories['web']->id], Build::query()->orderBy('id')->pluck('repository_id')->all(), 'Then the site.');
        Build::query()->update(['status' => Build::STATUS_SUCCEEDED]);
        $run();
        $this->assertSame('done', $launch->refresh()->status);

        $page = $this->actingAs($this->owner)->getJson("{$this->base}/launches/{$launch->id}")->assertOk();
        $page->assertJsonPath('launch.steps.4.state', 'done')->assertJsonPath('launch.apps.0.checklist.1.done', true);
        $this->assertSame('Point api.example.com at 203.0.113.10 (an A record)', $page->json('launch.apps.0.checklist.0.label'));
    }

    /**
     * A website that goes unhealthy stops the launch with the reason; Try again carries on. An app without a chosen
     * website, or a new server without a provider, is turned down before anything starts.
     *
     * @return void
     */
    public function test_a_launch_that_stops_can_carry_on(): void
    {
        $api = $this->bareWebsite('api.example.com', 'api');
        $web = $this->bareWebsite('www.example.com', 'web');
        $start = fn (array $overrides = []) => $this->actingAs($this->owner)->postJson("{$this->base}/launches", [
            'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'example.com',
            'server' => ['mode' => 'existing', 'server_id' => $this->server->id], 'apps' => [['website_id' => $api->id], ['website_id' => $web->id]], ...$overrides,
        ]);
        // An app with no website is refused, and so is a new server without a provider.
        $start(['apps' => [[], ['website_id' => $web->id]]])->assertJsonValidationErrors('apps.0.website_id');
        $start(['server' => ['mode' => 'new']])->assertJsonValidationErrors('server.provider_id');

        Queue::fake();
        $start()->assertOk();
        $launch = Launch::query()->sole();
        $api->forceFill(['provisioning_status' => Website::STATUS_FAILED])->save();
        (new RunLaunch($launch->id))->handle();
        $this->assertSame(['failed', 'The website api.example.com couldn’t be set up. Open it to see why.'], [$launch->refresh()->status, $launch->error]);

        $api->forceFill(['provisioning_status' => Website::STATUS_ACTIVE])->save();
        $this->actingAs($this->owner)->postJson("{$this->base}/launches/{$launch->id}/retry")->assertOk();
        $this->assertSame('queued', $launch->refresh()->status);
        $this->assertSame(2, Website::query()->whereIn('url', ['api.example.com', 'www.example.com'])->count(), 'Nothing was made twice.');
    }

    /**
     * A site built on GitHub Actions gets a pull request with its workflow, and its deploy waits until the workflow
     * is on the branch, then goes ahead.
     *
     * @return void
     */
    public function test_a_site_built_on_github_actions_waits_for_its_workflow(): void
    {
        $workflow = new stdClass;
        $workflow->merged = false;
        Http::fake([
            'api.github.com/repos/acme/shop/contents/.github*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'PUT') {
                    return Http::response(['content' => []], 201);
                }

                return cache('test.workflow-merged') === true ? Http::response(app(\App\Services\Deploy\GitHubActionsBuilds::class)->workflow(Repository::query()->where('deployment_root', 'web')->with(['website', 'environment', 'provider'])->firstOrFail())) : Http::response('', 404);
            },
            'api.github.com/repos/acme/shop/git/ref/heads/main' => Http::response(['object' => ['sha' => 'abc123']]),
            'api.github.com/repos/acme/shop/git/ref/heads/*' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/acme/shop/git/refs' => Http::response([], 201),
            'api.github.com/repos/acme/shop/pulls*' => fn (\Illuminate\Http\Client\Request $request) => $request->method() === 'GET' ? Http::response([]) : Http::response(['html_url' => 'https://github.com/acme/shop/pull/9'], 201),
        ]);
        $api = $this->bareWebsite('api.example.com', 'api');
        $web = $this->bareWebsite('www.example.com', 'web');
        Queue::fake();
        $this->actingAs($this->owner)->postJson("{$this->base}/launches", [
            'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'example.com',
            'server' => ['mode' => 'existing', 'server_id' => $this->server->id], 'apps' => [['website_id' => $api->id], ['website_id' => $web->id, 'githubActions' => true]],
        ])->assertOk();
        $launch = Launch::query()->sole();
        $run = fn () => (new RunLaunch($launch->id))->handle();
        // The websites already exist and are active, so the first run reaches the deploys and starts the API.
        $run();
        Build::query()->update(['status' => Build::STATUS_SUCCEEDED]);
        $run();

        $this->assertSame(1, Build::query()->count(), 'The site waits for its workflow.');
        $page = $this->actingAs($this->owner)->getJson("{$this->base}/launches/{$launch->id}")->assertOk();
        $page->assertJsonPath('launch.apps.1.workflow', ['url' => 'https://github.com/acme/shop/pull/9', 'error' => null, 'waiting' => true]);
        $this->assertTrue(Environment::query()->where('name', 'Production-web')->sole()->github_actions_builds);

        cache()->put('test.workflow-merged', true);
        $run();
        $this->assertSame(2, Build::query()->count(), 'Merged, so it deploys.');
    }

    /**
     * An app launched onto a website that already belongs to an environment keeps that environment (the repository is
     * connected to it, nothing new is made), while another app on an unassigned website gets its own environment.
     *
     * @return void
     */
    public function test_an_app_keeps_the_environment_of_the_website_it_launches_onto(): void
    {
        // One website already on the project's Production environment, and one not yet assigned to any environment.
        $production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $web = Website::factory()->create([
            'account_id' => $this->project->account_id, 'server_id' => $this->server->id, 'environment_id' => $production->id,
            'url' => 'shop.example.com', 'deployment_slug' => 'shop', 'provisioning_status' => Website::STATUS_ACTIVE, 'name' => 'Shop',
        ]);
        $api = $this->bareWebsite('api.shop.example.com', 'api');

        $this->actingAs($this->owner)->getJson("{$this->base}/launch")->assertOk()->assertJsonCount(2, 'websites');

        Queue::fake();
        $this->actingAs($this->owner)->postJson("{$this->base}/launches", [
            'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'shop.example.com',
            'server' => ['mode' => 'existing', 'server_id' => $this->server->id],
            'apps' => [['website_id' => $api->id], ['website_id' => $web->id]],
        ])->assertOk();
        $launch = Launch::query()->sole();
        (new RunLaunch($launch->id))->handle();

        // The Nuxt app kept Production; the API got its own environment rather than landing on Production too.
        $this->assertSame($production->id, $web->refresh()->environment_id, 'The chosen website keeps its environment.');
        $this->assertSame(1, Repository::query()->where('website_id', $web->id)->where('environment_id', $production->id)->count(), 'The repository is connected to it.');
        $this->assertNotSame($production->id, $api->refresh()->environment_id, 'The other app got its own environment.');
    }

    /**
     * A website already serving a repository isn't offered on the launch form, and choosing one anyway is refused.
     *
     * @return void
     */
    public function test_a_website_that_already_has_a_repository_cannot_be_launched_onto(): void
    {
        $production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $taken = Website::factory()->create(['account_id' => $this->project->account_id, 'server_id' => $this->server->id, 'environment_id' => $production->id, 'url' => 'taken.example.com', 'provisioning_status' => Website::STATUS_ACTIVE]);
        Repository::factory()->create(['project_id' => $this->project->id, 'provider_id' => $this->github->id, 'website_id' => $taken->id, 'environment_id' => $production->id]);

        $free = $this->bareWebsite('api.example.com', 'api');

        $this->actingAs($this->owner)->getJson("{$this->base}/launch")->assertOk()->assertJsonMissing(['domain' => 'taken.example.com']);
        $this->actingAs($this->owner)->postJson("{$this->base}/launches", [
            'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'example.com',
            'server' => ['mode' => 'existing', 'server_id' => $this->server->id],
            'apps' => [['website_id' => $free->id], ['website_id' => $taken->id]],
        ])->assertJsonValidationErrors('apps.1.website_id');
    }
}
