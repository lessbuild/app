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
     * The plan proposes the API on api.example.com and the site on example.com, each with its environment, and
     * the server it fits on; launching sets everything up in order as the server and websites come up, deploys
     * the API before the site, and the page then shows the checklist.
     *
     * @return void
     */
    public function test_a_repository_launches_in_one_go(): void
    {
        $this->actingAs($this->owner)->getJson("{$this->base}/launch")->assertOk()->assertJsonPath('sources.0.value', (string) $this->github->id);
        $plan = $this->actingAs($this->owner)->postJson("{$this->base}/launch/plan", ['provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'example.com'])->assertOk()->json('plan');

        [$api, $web] = $plan['apps'];
        $this->assertSame(['Laravel 12', 'api.example.com', 'Production', true, true, true, ['STRIPE_KEY', 'STRIPE_SECRET'], ['api/**']], [$api['label'], $api['domain'], $api['environment'], $api['mysql'], $api['queue'], $api['scheduler'], $api['secrets'], $api['includePaths']]);
        $this->assertSame(['Nuxt 4', 'example.com', 'Production-web', false], [$web['label'], $web['domain'], $web['environment'], $web['mysql']]);
        $this->assertSame([$this->server->id], array_column($plan['servers'], 'id'));

        Queue::fake();
        $this->actingAs($this->owner)->postJson("{$this->base}/launches", [
            'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'example.com',
            'server' => ['mode' => 'existing', 'server_id' => $this->server->id],
            'apps' => [['secretValues' => ['STRIPE_SECRET' => 'sk_live_1', 'NOT_ASKED' => 'x']], ['domain' => 'www.example.com']],
        ])->assertOk();
        $launch = Launch::query()->sole();
        Queue::assertPushed(RunLaunch::class);

        $run = fn () => (new RunLaunch($launch->id))->handle();
        $run();
        $this->assertSame(['waiting', 'websites'], [$launch->refresh()->status, $launch->step]);
        $websites = Website::query()->whereIn('url', ['api.example.com', 'www.example.com'])->get();
        $this->assertTrue(Website::query()->where('url', 'api.example.com')->sole()->has_database);
        $this->assertFalse(Website::query()->where('url', 'www.example.com')->sole()->has_database, 'The Nuxt site gets no database.');

        Website::query()->whereKey($websites->pluck('id'))->update(['provisioning_status' => Website::STATUS_ACTIVE]);
        $run();
        $this->assertSame(['waiting', 'deploys'], [$launch->refresh()->status, $launch->step]);
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
     * A website that fails stops the launch with the reason; Try again carries on, keeping what it made. A domain
     * another website has, or a new server without a provider, is turned down before anything starts.
     *
     * @return void
     */
    public function test_a_launch_that_stops_can_carry_on(): void
    {
        $start = fn (array $overrides = []) => $this->actingAs($this->owner)->postJson("{$this->base}/launches", [
            'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'example.com',
            'server' => ['mode' => 'existing', 'server_id' => $this->server->id], 'apps' => [[], []], ...$overrides,
        ]);
        Website::factory()->create(['url' => 'api.taken.com']);
        $message = (string) $start(['domain' => 'taken.com'])->assertJsonValidationErrors('apps.0.domain')->json('errors')['apps.0.domain'][0];
        $this->assertStringContainsString('api.taken.com already belongs to another website', $message, 'The error names the taken domain.');
        $this->assertStringContainsString('api-2.taken.com', $message, 'The error suggests a free domain, not the taken one.');
        $start(['domain' => 'not a domain'])->assertJsonValidationErrors(['apps.0.domain' => 'Enter a valid domain']);
        $start(['server' => ['mode' => 'new']])->assertJsonValidationErrors('server.provider_id');

        Queue::fake();
        $start()->assertOk();
        $launch = Launch::query()->sole();
        (new RunLaunch($launch->id))->handle();
        Website::query()->where('url', 'api.example.com')->update(['provisioning_status' => Website::STATUS_FAILED]);
        (new RunLaunch($launch->id))->handle();
        $this->assertSame(['failed', 'The website api.example.com couldn’t be set up. Open it to see why.'], [$launch->refresh()->status, $launch->error]);

        $this->actingAs($this->owner)->postJson("{$this->base}/launches/{$launch->id}/retry")->assertOk();
        $this->assertSame('queued', $launch->refresh()->status);
        $this->assertSame(2, Website::query()->whereIn('url', ['api.example.com', 'example.com'])->count(), 'Nothing was made twice.');
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
        Queue::fake();
        $this->actingAs($this->owner)->postJson("{$this->base}/launches", [
            'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'example.com',
            'server' => ['mode' => 'existing', 'server_id' => $this->server->id], 'apps' => [[], ['githubActions' => true]],
        ])->assertOk();
        $launch = Launch::query()->sole();
        $run = fn () => (new RunLaunch($launch->id))->handle();
        $run();
        Website::query()->update(['provisioning_status' => Website::STATUS_ACTIVE]);
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
     * An app can launch onto a website you already added (with its domain), rather than a new one: the launch form
     * offers it, launching uses it instead of making another, keeps its environment, and connects the repository to it.
     *
     * @return void
     */
    public function test_an_app_can_launch_onto_a_website_you_already_have(): void
    {
        // A website added earlier through the setup guide: on the project's Production environment, nothing deploying to it.
        $production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $existing = Website::factory()->create([
            'account_id' => $this->project->account_id, 'server_id' => $this->server->id, 'environment_id' => $production->id,
            'url' => 'shop.example.com', 'deployment_slug' => 'shop-site', 'provisioning_status' => Website::STATUS_ACTIVE, 'name' => 'Shop',
        ]);

        // It shows on the launch form as a target an app can go onto.
        $this->actingAs($this->owner)->getJson("{$this->base}/launch")->assertOk()->assertJsonPath('websites.0.domain', 'shop.example.com');

        Queue::fake();
        $this->actingAs($this->owner)->postJson("{$this->base}/launches", [
            'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'shop.example.com',
            'server' => ['mode' => 'existing', 'server_id' => $this->server->id],
            'apps' => [['domain' => 'api.shop.example.com'], ['website_id' => $existing->id]],
        ])->assertOk();
        $launch = Launch::query()->sole();
        $run = fn () => (new RunLaunch($launch->id))->handle();

        $run();
        $this->assertSame(1, Website::query()->where('url', 'shop.example.com')->count(), 'No second website is made for the chosen domain.');
        Website::query()->whereIn('url', ['api.shop.example.com', 'shop.example.com'])->update(['provisioning_status' => Website::STATUS_ACTIVE]);
        $run();

        $this->assertSame($production->id, $existing->refresh()->environment_id, 'The chosen website keeps its environment.');
        $this->assertSame(1, Repository::query()->where('website_id', $existing->id)->where('environment_id', $production->id)->count(), 'The repository is connected to it.');
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

        $this->actingAs($this->owner)->getJson("{$this->base}/launch")->assertOk()->assertJsonMissing(['domain' => 'taken.example.com']);
        $this->actingAs($this->owner)->postJson("{$this->base}/launches", [
            'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'domain' => 'example.com',
            'server' => ['mode' => 'existing', 'server_id' => $this->server->id],
            'apps' => [[], ['website_id' => $taken->id]],
        ])->assertJsonValidationErrors('apps.1.website_id');
    }
}
