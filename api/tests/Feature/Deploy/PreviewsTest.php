<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\AccountRole;
use App\Enums\EnvironmentKind;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\DatabaseClone;
use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentRecipe;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Deploy\PreviewConfiguration;
use App\Services\Deploy\RepositoryDeploymentPlan;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use App\Services\Infrastructure\WebsiteProvisioner;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class PreviewsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private const REVISION = '0123456789abcdef0123456789abcdef01234567';

    private const SECRET = 'webhook-secret';

    private Project $project;

    private User $owner;

    private Environment $production;

    private Repository $source;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create(['slug' => 'shop']);
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id, 'name' => 'Shop', 'deployment_slug' => 'shop', 'env_file' => "APP_KEY=production-key\n"]);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'token' => 'ghp_secret']);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->source = Repository::factory()->create([
            'project_id' => $this->project->id, 'website_id' => $website->id, 'provider_id' => $github->id, 'environment_id' => $this->production->id,
            'name' => 'shop', 'url' => 'github.com/acme/shop', 'branch' => 'main', 'webhook_enabled' => true, 'webhook_secret' => self::SECRET,
            'previews_enabled' => true, 'preview_domain' => 'preview.example.com', 'preview_initialization_command' => 'php artisan migrate --seed',
        ]);
        $this->variable('APP_NAME', 'Shop', secret: false);
        $this->variable('STRIPE_SECRET', 'sk_live_123');
        $this->base = "/api/app/projects/{$this->project->id}/deploy";
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * A pull request gets its own stack and deploys once its website is ready.
     */
    public function test_a_pull_request_gets_its_own_stack_and_deploys_once_its_website_is_ready(): void
    {
        $process = new EnvironmentProcess;
        $process->forceFill(['environment_id' => $this->production->id, 'name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work', 'replicas' => 3, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true])->save();
        foreach (['cache' => 'valkey', 'search' => 'object_storage'] as $name => $type) {
            $resource = new EnvironmentResource;
            $resource->forceFill(['environment_id' => $this->production->id, 'name' => $name, 'type' => $type, 'is_managed' => $type === 'valkey', 'status' => 'ready', 'configuration' => ['variables' => ['S3_KEY' => 'production']]])->save();
        }

        $recipe = new EnvironmentRecipe;
        $recipe->forceFill(['environment_id' => $this->production->id, 'position' => 1, 'name' => 'Tools', 'script' => 'echo tools'])->save();

        $this->pullRequest('d-1')->assertStatus(202)->assertJson(['status' => 'provisioning']);
        $this->pullRequest('d-1')->assertOk()->assertJson(['status' => 'duplicate']);

        $preview = Preview::query()->sole();
        $website = $preview->website;
        $environment = $preview->environment;
        $repository = $preview->repository;
        $this->assertNotNull($website);
        $this->assertNotNull($environment);
        $this->assertNotNull($repository);
        $this->assertSame([Preview::STATUS_PROVISIONING, 12, 'feature/checkout', 'pr-12-shop.preview.example.com'], [$preview->status, $preview->pull_request_number, $preview->source_branch, $website->url]);
        $this->assertSame([EnvironmentKind::Preview, 'pr-12', 'PR #12'], [$environment->kind, $environment->slug, $environment->name]);
        $this->assertSame(['feature/checkout', false, $environment->id], [$repository->branch, $repository->webhook_enabled, $repository->environment_id]);
        $this->assertSame(Website::STATUS_PROVISIONING, $website->provisioning_status);
        $this->assertStringContainsString("/websites/{$website->id}/provisioning/callback/status", $this->scripts->started[0]['script']);

        // Preview-owned values, the source's non-secret variables, and no secrets or source `.env`.
        $env = (string) $website->env_file;
        foreach (['APP_ENV="preview"', 'APP_NAME="Shop"', 'APP_URL="https://pr-12-shop.preview.example.com"', 'BUILDPUSHER_PREVIEW="12"', 'DB_DATABASE="'.$website->databaseIdentifier().'"'] as $line) {
            $this->assertStringContainsString($line, $env);
        }
        $this->assertStringNotContainsString('sk_live_123', $env);
        $this->assertStringNotContainsString('production-key', $env);

        // Processes and managed caches are copied; external resources aren't.
        $this->assertSame(['queue', 1], [$environment->processes()->sole()->name, $environment->processes()->sole()->replicas]);
        $this->assertSame(['Tools', 'echo tools'], [$environment->recipes()->sole()->name, $environment->recipes()->sole()->script]);
        $cache = $environment->resources()->sole();
        $this->assertSame('buildpusher-valkey-'.strtolower($environment->id).'-cache', $cache->configuration['container_name'] ?? null);

        foreach (range(1, WebsiteProvisioner::finalStage()) as $stage) {
            $this->post(ProvisioningCallbackUrl::websiteStatus($website), ['status' => $stage])->assertNoContent();
        }
        $build = Build::query()->where('repository_id', $repository->id)->sole();
        $this->assertSame([Build::STATUS_RUNNING, 'preview', self::REVISION], [$build->status, $build->trigger_source, $build->revision]);
        $this->assertSame('php artisan migrate --seed', $build->environment_payload['preview_initialization']['command'] ?? null);
        $this->assertSame(Preview::STATUS_DEPLOYING, $preview->refresh()->status);

        $this->finish($build);
        $preview->refresh();
        $this->assertSame(Preview::STATUS_READY, $preview->status);
        $this->assertNotNull($preview->initialized_at);

        $this->actingAs($this->owner)->getJson("{$this->base}/previews")->assertOk()->assertJsonPath('open.0.pullRequest', 12)->assertSee('pr-12-shop.preview.example.com')->assertJsonPath('open.0.status', Preview::STATUS_READY)->assertSee('STRIPE_SECRET');
        $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertJsonCount(1, 'repositories');
        $this->actingAs($this->owner)->getJson("{$this->base}/environments")->assertOk()->assertJsonMissingPath('environments.'.$this->project->environments()->count());
    }

    /**
     * Check any branch can get a preview that deploys its latest commit and closes on its own date: the same stack as
     * a pull request's, named for the branch, no pull request comment, reopening it moves the date, and it closes once
     * the date passes.
     *
     * @return void
     */
    public function test_a_branch_gets_a_preview_that_closes_on_its_date(): void
    {
        Http::fake();
        $this->actingAs($this->owner)->postJson("{$this->base}/previews/branch", ['repository_id' => $this->source->id, 'branch' => 'bad..name', 'days' => 3])->assertJsonValidationErrors('branch');
        $this->actingAs($this->owner)->postJson("{$this->base}/previews/branch", ['repository_id' => $this->source->id, 'branch' => 'feature/new-cart', 'days' => 3])->assertSuccessful();

        $preview = Preview::query()->sole();
        $this->assertSame([null, 'feature/new-cart', 'br-feature-new-cart-shop.preview.example.com', 'Branch feature/new-cart'], [$preview->pull_request_number, $preview->source_branch, $preview->url, $preview->label()]);
        $this->assertSame(['br-feature-new-cart', 'Branch feature/new-cart'], [$preview->environment?->slug, $preview->environment?->name]);
        $this->assertTrue($preview->expiresAt()->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
        $this->assertStringContainsString('BUILDPUSHER_PREVIEW="feature/new-cart"', (string) $preview->website?->env_file);

        $website = $preview->website;
        $this->assertNotNull($website);
        foreach (range(1, WebsiteProvisioner::finalStage()) as $stage) {
            $this->post(ProvisioningCallbackUrl::websiteStatus($website), ['status' => $stage])->assertNoContent();
        }
        $build = Build::query()->where('repository_id', $preview->repository_id)->sole();
        $this->assertSame(['preview', null], [$build->trigger_source, $build->revision], 'It deploys the branch’s latest commit.');
        Http::assertNothingSent();
        $this->actingAs($this->owner)->getJson("{$this->base}/previews")->assertOk()->assertJsonPath('open.0.branch', 'feature/new-cart')->assertJsonPath('allowed', true);

        $this->finish($build);
        $this->actingAs($this->owner)->postJson("{$this->base}/previews/branch", ['repository_id' => $this->source->id, 'branch' => 'feature/new-cart', 'days' => 14])->assertSuccessful();
        $this->assertSame(1, Preview::query()->count(), 'Opening it again moves the date.');
        $this->assertTrue($preview->refresh()->expiresAt()->isAfter(now()->addDays(13)));

        $this->travel(15)->days();
        $expire = $this->artisan('previews:expire');
        $this->assertInstanceOf(\Illuminate\Testing\PendingCommand::class, $expire);
        $expire->assertSuccessful()->run();
        $this->assertSame(Preview::STATUS_CLOSED, $preview->refresh()->status);
    }

    /**
     * A new revision deploys after the running one and the initialisation runs until it succeeds.
     */
    public function test_a_new_revision_deploys_after_the_running_one_and_the_initialisation_runs_until_it_succeeds(): void
    {
        [$preview, $build] = $this->readyToDeploy();
        $next = str_repeat('b', 40);
        $this->pullRequest('d-2', ['action' => 'synchronize', 'pull_request' => ['head' => ['sha' => $next]]])->assertStatus(202)->assertJson(['status' => 'deploying']);
        $this->assertSame(1, Build::query()->where('repository_id', $preview->repository_id)->count());

        $this->post(ProvisioningCallbackUrl::buildFailure($build), ['message' => 'Tests failed'])->assertNoContent();
        $preview->refresh();
        $this->assertSame([Preview::STATUS_DEPLOYING, null], [$preview->status, $preview->initialized_at]);
        $second = Build::query()->where('repository_id', $preview->repository_id)->latest('id')->firstOrFail();
        $this->assertSame($next, $second->revision);
        $this->assertArrayHasKey('preview_initialization', $second->environment_payload ?? []);

        $this->finish($second);
        $this->assertSame(Preview::STATUS_READY, $preview->refresh()->status);
        $this->pullRequest('d-3', ['action' => 'synchronize', 'pull_request' => ['head' => ['sha' => str_repeat('c', 40)]]])->assertStatus(202);
        $this->assertArrayNotHasKey('preview_initialization', Build::query()->latest('id')->firstOrFail()->environment_payload ?? []);
    }

    /**
     * Pull requests that cant have a preview are refused.
     */
    public function test_pull_requests_that_cant_have_a_preview_are_refused(): void
    {
        $this->pullRequest('d-1', ['pull_request' => ['head' => ['repo' => ['full_name' => 'someone/shop']]]])->assertOk()->assertJson(['status' => 'preview_fork_blocked']);
        $this->pullRequest('d-2', ['pull_request' => ['base' => ['ref' => 'develop']]])->assertOk()->assertJson(['status' => 'preview_target_ignored']);
        $this->pullRequest('d-3', ['pull_request' => ['base' => ['repo' => ['full_name' => 'acme/other']]]])->assertOk()->assertJson(['status' => 'preview_source_ignored']);
        $this->pullRequest('d-4', ['pull_request' => ['head' => ['repo' => null]]])->assertOk()->assertJson(['status' => 'preview_source_unverified']);
        $this->pullRequest('d-5', ['action' => 'labeled'])->assertOk()->assertJson(['status' => 'event_ignored']);
        $this->pullRequest('d-6', ['action' => 'closed'])->assertOk()->assertJson(['status' => 'preview_not_found']);

        $this->source->forceFill(['previews_enabled' => false])->save();
        $this->pullRequest('d-7')->assertOk()->assertJson(['status' => 'preview_ignored']);
        $this->source->forceFill(['previews_enabled' => true])->save();
        $this->onTier($this->project, 'deploy', 'starter');
        $this->pullRequest('d-8')->assertOk()->assertJson(['status' => 'preview_plan_required']);
        $this->assertSame(0, Preview::query()->count());
        $this->assertSame('preview_plan_required', $this->source->webhookDeliveries()->where('delivery_id', 'd-8')->value('status'));

        // Pro allows five open previews across the account.
        $this->onTier($this->project, 'deploy', 'pro');
        foreach (range(1, 5) as $number) {
            $preview = new Preview;
            $preview->forceFill(['project_id' => $this->project->id, 'source_repository_id' => $this->source->id, 'pull_request_number' => 100 + $number, 'source_branch' => 'x', 'revision' => self::REVISION, 'status' => Preview::STATUS_READY, 'last_activity_at' => now()])->save();
        }
        $this->pullRequest('d-9')->assertOk()->assertJson(['status' => 'preview_limit_reached']);
    }

    /**
     * Closing removes the stack once the deploy finishes and reopening brings it back.
     */
    public function test_closing_removes_the_stack_once_the_deploy_finishes_and_reopening_brings_it_back(): void
    {
        [$preview, $build] = $this->readyToDeploy();
        $website = $preview->website;
        $this->assertNotNull($website);
        $environmentId = $preview->environment_id;
        $resource = new EnvironmentResource;
        $resource->forceFill(['environment_id' => $environmentId, 'name' => 'cache', 'type' => 'valkey', 'is_managed' => true, 'status' => 'ready', 'configuration' => EnvironmentResource::managedCache((string) $environmentId, 'valkey', 'cache')])->save();

        $this->pullRequest('d-2', ['action' => 'closed'])->assertOk()->assertJson(['status' => 'closed']);
        $preview->refresh();
        $this->assertSame([Preview::STATUS_CLOSED, null], [$preview->status, $preview->cleanup_status]);
        $this->assertSame([], $this->shell->ran);

        $this->finish($build);
        $preview->refresh();
        $this->assertSame([Preview::STATUS_CLOSED, Preview::CLEANUP_SUCCEEDED, 1], [$preview->status, $preview->cleanup_status, $preview->cleanup_attempts]);
        $this->assertTrue($website->refresh()->trashed());
        $commands = implode("\n", array_column($this->shell->ran, 'command'));
        $this->assertStringContainsString("docker rm --force 'buildpusher-valkey-".strtolower((string) $environmentId)."-cache'", $commands);
        $this->assertStringContainsString("grep -Fq -- 'ExecStart=/var/www/{$website->deployment_slug}/shared/'", $commands);
        $this->assertStringContainsString("rm -rf -- '/var/www/{$website->deployment_slug}'", $commands);
        $this->actingAs($this->owner)->getJson("{$this->base}/previews")->assertOk()->assertJsonPath('closed.0.cleanupStatus', Preview::CLEANUP_SUCCEEDED);

        $started = count($this->scripts->started);
        $this->pullRequest('d-3', ['action' => 'reopened'])->assertStatus(202)->assertJson(['status' => 'provisioning']);
        $preview->refresh();
        $website->refresh();
        $this->assertSame([Preview::STATUS_PROVISIONING, null, $environmentId], [$preview->status, $preview->cleanup_status, $preview->environment_id]);
        $this->assertFalse($website->trashed());
        $this->assertSame(Website::STATUS_PROVISIONING, $website->provisioning_status);
        $this->assertCount($started + 1, $this->scripts->started);
    }

    /**
     * A failed cleanup can be retried and stalled ones fail.
     */
    public function test_a_failed_cleanup_can_be_retried_and_stalled_ones_fail(): void
    {
        $this->pullRequest('d-1')->assertStatus(202);
        $preview = Preview::query()->sole();
        $resource = new EnvironmentResource;
        $resource->forceFill(['environment_id' => $preview->environment_id, 'name' => 'cache', 'type' => 'valkey', 'is_managed' => true, 'status' => 'ready', 'configuration' => EnvironmentResource::managedCache((string) $preview->environment_id, 'valkey', 'cache')])->save();
        $this->shell->reply('docker: permission denied', 1);

        $this->actingAs($this->owner)->postJson("{$this->base}/previews/{$preview->id}/close")->assertJsonRedirect("{$this->base}/previews");
        $preview->refresh();
        $this->assertSame(Preview::CLEANUP_FAILED, $preview->cleanup_status);
        $this->assertStringContainsString('docker: permission denied', (string) $preview->cleanup_error);
        $this->assertFalse($preview->website?->trashed());
        $this->actingAs($this->owner)->getJson("{$this->base}/previews")->assertSee('docker: permission denied')->assertJsonPath('closed.0.canOperate', true);

        $this->actingAs($this->owner)->postJson("{$this->base}/previews/{$preview->id}/cleanup")->assertSuccessful();
        $this->assertSame([Preview::CLEANUP_SUCCEEDED, 2], [$preview->refresh()->cleanup_status, $preview->cleanup_attempts]);
        $this->actingAs($this->owner)->postJson("{$this->base}/previews/{$preview->id}/cleanup")->assertStatus(Response::HTTP_CONFLICT);

        $preview->forceFill(['cleanup_status' => Preview::CLEANUP_RUNNING])->save();
        Preview::query()->whereKey($preview->id)->update(['updated_at' => now()->subHour()]);
        $this->command('previews:expire')->expectsOutput('Closed 0 expired previews; 1 stalled cleanups can be retried.');
        $this->assertSame(Preview::CLEANUP_FAILED, $preview->refresh()->cleanup_status);
    }

    /**
     * Approved secrets reach one revision only.
     */
    public function test_approved_secrets_reach_one_revision_only(): void
    {
        [$preview, $build] = $this->readyToDeploy();
        $this->finish($build);
        $this->variable('APP_KEY', 'base64:source-key');
        $this->variable('BUILD_TOKEN', 'build-only', scope: 'build');
        $page = $this->actingAs($this->owner)->getJson("{$this->base}/previews")->assertOk();
        $page->assertSee('STRIPE_SECRET')->assertDontSee('BUILD_TOKEN');

        $this->actingAs($this->owner)->postJson("{$this->base}/previews/{$preview->id}/secrets", ['revision' => self::REVISION, 'secret_keys' => ['APP_KEY']])->assertJsonValidationErrors('secret_keys');
        $this->actingAs($this->owner)->postJson("{$this->base}/previews/{$preview->id}/secrets", ['revision' => str_repeat('d', 40), 'secret_keys' => ['STRIPE_SECRET']])->assertStatus(Response::HTTP_CONFLICT);
        $this->actingAs($this->owner)->postJson("{$this->base}/previews/{$preview->id}/secrets", ['revision' => self::REVISION, 'secret_keys' => ['STRIPE_SECRET']])->assertSuccessful();

        $this->assertStringContainsString('STRIPE_SECRET="sk_live_123"', (string) $preview->website?->refresh()->env_file);
        $this->assertSame(['sk_live_123'], array_values(app(PreviewConfiguration::class)->approvedSecrets($preview->refresh())));
        $redeploy = Build::query()->where('repository_id', $preview->repository_id)->latest('id')->firstOrFail();
        $this->assertNotSame($build->id, $redeploy->id);
        $this->assertSame(Preview::STATUS_DEPLOYING, $preview->status);
        $this->actingAs($this->owner)->getJson("{$this->base}/previews")->assertJsonPath('open.0.approvedSecrets.count', 1);

        // A rotated secret needs approving again, as does a new revision.
        EnvironmentVariable::query()->where('key', 'STRIPE_SECRET')->update(['current_version' => 2]);
        $this->assertSame([], app(PreviewConfiguration::class)->approvedSecrets($preview));
        EnvironmentVariable::query()->where('key', 'STRIPE_SECRET')->update(['current_version' => 1]);
        $this->finish($redeploy);
        $this->pullRequest('d-2', ['action' => 'synchronize', 'pull_request' => ['head' => ['sha' => str_repeat('e', 40)]]])->assertStatus(202);
        $this->assertSame([], app(PreviewConfiguration::class)->approvedSecrets($preview->refresh()));
        $this->assertStringNotContainsString('sk_live_123', (string) $preview->website?->refresh()->env_file);
        $this->assertNotNull($preview->secretApprovals()->sole()->revoked_at);
    }

    /**
     * Who may do what with previews.
     */
    public function test_who_may_do_what_with_previews(): void
    {
        $this->pullRequest('d-1')->assertStatus(202);
        $preview = Preview::query()->sole();
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->getJson("{$this->base}/previews")->assertOk()->assertJsonPath('open.0.pullRequest', 12)->assertJsonPath('open.0.canOperate', false)->assertDontSee('STRIPE_SECRET');
        $this->actingAs($viewer)->postJson("{$this->base}/previews/{$preview->id}/close")->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->base}/previews/{$preview->id}/secrets", ['revision' => self::REVISION, 'secret_keys' => ['STRIPE_SECRET']])->assertForbidden();

        $other = Project::factory()->withServices(['deploy'])->create();
        $this->actingAs($this->ownerOf($other))->postJson("/api/app/projects/{$other->id}/deploy/previews/{$preview->id}/close")->assertNotFound();
    }

    /**
     * Settings are saved on the repository and need the plan.
     */
    public function test_settings_are_saved_on_the_repository_and_need_the_plan(): void
    {
        $url = "{$this->base}/repositories/{$this->source->id}/previews";
        $this->actingAs($this->owner)->getJson("{$this->base}/repositories/{$this->source->id}")->assertOk()->assertJsonStructure(['repository' => ['previewsEnabled', 'previewDomain', 'previewTtlHours']]);
        $this->actingAs($this->owner)->putJson($url, ['previews_enabled' => '1', 'preview_domain' => 'https://*.Preview.Example.org/', 'preview_ttl_hours' => 24, 'preview_initialization_command' => ' '])->assertSuccessful();
        $this->source->refresh();
        $this->assertSame([true, 'preview.example.org', 24, null], [$this->source->previews_enabled, $this->source->preview_domain, $this->source->preview_ttl_hours, $this->source->preview_initialization_command]);
        $this->actingAs($this->owner)->putJson($url, ['previews_enabled' => '1', 'preview_domain' => 'not a domain', 'preview_ttl_hours' => 24])->assertJsonValidationErrors('preview_domain');
        $this->actingAs($this->owner)->putJson($url, ['previews_enabled' => '1', 'preview_domain' => 'preview.example.org', 'preview_ttl_hours' => 0])->assertJsonValidationErrors('preview_ttl_hours');

        $this->onTier($this->project, 'deploy', 'starter');
        $this->actingAs($this->owner)->putJson($url, ['previews_enabled' => '1', 'preview_domain' => 'preview.example.org', 'preview_ttl_hours' => 24])->assertJsonValidationErrors('previews_enabled');
        $this->actingAs($this->owner)->putJson($url, ['previews_enabled' => '0', 'preview_ttl_hours' => 24])->assertSuccessful();
        $this->assertFalse($this->source->refresh()->previews_enabled);
    }

    /**
     * Previews can start with a copy of another websites database.
     */
    public function test_previews_can_start_with_a_copy_of_another_websites_database(): void
    {
        $staging = Website::factory()->create(['server_id' => $this->source->website->server_id, 'name' => 'Shop staging', 'deployment_slug' => 'shop-staging']);
        $elsewhere = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id])->id]);
        $settings = ['previews_enabled' => '1', 'preview_domain' => 'preview.example.com', 'preview_ttl_hours' => 72];
        $url = "{$this->base}/repositories/{$this->source->id}/previews";
        $this->actingAs($this->owner)->getJson("{$this->base}/repositories/{$this->source->id}")->assertOk()->assertSee('Shop staging');
        $this->actingAs($this->owner)->putJson($url, [...$settings, 'preview_database_source_website_id' => $elsewhere->id])->assertJsonValidationErrors('preview_database_source_website_id');
        $this->actingAs($this->owner)->putJson($url, [...$settings, 'preview_database_source_website_id' => $staging->id, 'preview_database_mode' => 'sample'])->assertSuccessful();
        $this->assertSame('sample', $this->source->refresh()->preview_database_mode);

        [$preview, $build] = $this->readyToDeploy();
        $clone = DatabaseClone::query()->sole();
        $this->assertSame([$staging->id, $preview->website_id, 'succeeded', null, 'sample'], [$clone->source_website_id, $clone->target_website_id, $clone->status, $clone->requested_by, $clone->mode]);
        $this->assertNotNull($preview->database_copied_at);
        $copy = collect($this->shell->ran)->search(fn (array $run): bool => str_contains($run['command'], 'mysqldump'));
        $this->assertIsInt($copy, 'The database was copied before the first deploy.');
        $this->assertStringContainsString("--where='1 LIMIT 1000'", $this->shell->ran[$copy]['command'], 'A sample takes the first rows of each table.');
        $this->assertSame(Build::STATUS_RUNNING, $build->status);

        $this->assertNotNull($preview->website);
        app(\App\Services\Deploy\Previews::class)->websiteReady($preview->website);
        $this->assertSame(1, DatabaseClone::query()->count(), 'Only copied once.');
    }

    /**
     * Previews expire and close with their source repository.
     */
    public function test_previews_expire_and_close_with_their_source_repository(): void
    {
        $this->markTestSkipped('Needs the Infrastructure API (slice 5): the account recipe library and websites.');
        $this->pullRequest('d-1')->assertStatus(202);
        $this->pullRequest('d-2', ['number' => 13])->assertStatus(202);
        [$first, $second] = Preview::query()->orderBy('id')->get()->all();
        $this->assertNotNull($first->repository);
        $this->assertNotNull($first->website);

        // A preview's own repository and website go with the preview.
        $this->actingAs($this->owner)->deleteJson("{$this->base}/repositories/{$first->repository->id}")->assertStatus(Response::HTTP_CONFLICT);
        $this->actingAs($this->owner)->deleteJson("/api/app/projects/{$this->project->id}/infrastructure/websites/{$first->website->id}")->assertStatus(Response::HTTP_CONFLICT);

        $this->travel(71)->hours();
        $this->pullRequest('d-3', ['number' => 13, 'action' => 'synchronize', 'pull_request' => ['head' => ['sha' => str_repeat('f', 40)]]])->assertStatus(202);
        $this->travel(2)->hours();
        $this->command('previews:expire')->expectsOutput('Closed 1 expired previews; 0 stalled cleanups can be retried.');
        $this->assertSame([Preview::STATUS_CLOSED, Preview::STATUS_PROVISIONING], [$first->refresh()->status, $second->refresh()->status]);

        $this->actingAs($this->owner)->deleteJson("{$this->base}/repositories/{$this->source->id}")->assertSuccessful();
        $this->assertSame(Preview::STATUS_CLOSED, $second->refresh()->status);
    }

    /**
     * Github app repositories hear about their previews.
     */
    public function test_github_app_repositories_hear_about_their_previews(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $private);
        config(['github-app.id' => '12345', 'github-app.slug' => 'buildpusher', 'github-app.webhook_secret' => self::SECRET, 'github-app.private_key' => $private]);
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_installation_token'], 201),
            'api.github.com/repos/acme/shop/check-runs' => Http::response(['id' => 1], 201),
            // The comment exists once the first report has posted it.
            'api.github.com/repos/acme/shop/issues/12/comments*' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(collect(Http::recorded())->contains(fn (array $pair): bool => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/issues/12/comments')) ? [['id' => 55, 'body' => "<!-- buildpusher-preview -->\nold"]] : [])
                : Http::response(['id' => 55], 201),
            'api.github.com/repos/acme/shop/issues/comments/55' => Http::response(['id' => 55]),
        ]);
        $this->source->provider?->forceFill(['credential_type' => 'app', 'external_id' => '77'])->save();

        $this->pullRequest('d-1')->assertStatus(202);
        $preview = Preview::query()->sole();
        $this->actingAs($this->owner)->postJson("{$this->base}/previews/{$preview->id}/close")->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/check-runs') && $request['head_sha'] === self::REVISION && $request['status'] === 'in_progress');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/check-runs') && ($request['conclusion'] ?? null) === 'cancelled');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/issues/12/comments') && str_contains((string) $request['body'], 'preparing the preview'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/issues/comments/55') && str_contains((string) $request['body'], 'has been closed'));
    }

    /**
     * Deleting a website stops only its own units.
     */
    public function test_deleting_a_website_stops_only_its_own_units(): void
    {
        $this->markTestSkipped('Needs the Infrastructure API (slice 5): the account recipe library and websites.');
        $website = $this->source->website;
        $this->source->delete();
        $this->actingAs($this->owner)->deleteJson("/api/app/projects/{$this->project->id}/infrastructure/websites/{$website->id}")->assertSuccessful();
        $command = $this->shell->ran[0]['command'] ?? '';
        $this->assertStringContainsString("for unit_file in '/etc/systemd/system/buildpusher-shop-'*.service; do", $command);
        $this->assertStringContainsString("grep -Fq -- 'ExecStart=/var/www/shop/shared/'", $command);
        $this->assertStringContainsString("grep -E -- '^buildpusher-shop-web-[0-9]+$'", $command);
    }

    /**
     * Open pull request 12 and finish setting up its website, so its first deploy is running.
     *
     * @return array{Preview, Build}
     */
    private function readyToDeploy(): array
    {
        $this->pullRequest('d-1')->assertStatus(202);
        $preview = Preview::query()->sole();
        $this->assertNotNull($preview->website);
        foreach (range(1, WebsiteProvisioner::finalStage()) as $stage) {
            $this->post(ProvisioningCallbackUrl::websiteStatus($preview->website), ['status' => $stage])->assertNoContent();
        }

        return [$preview->refresh(), Build::query()->where('repository_id', $preview->repository_id)->sole()];
    }

    /**
     * Report a build's last stage, so it succeeds.
     *
     * @param  Build  $build
     * @return void
     */
    private function finish(Build $build): void
    {
        $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => app(RepositoryDeploymentPlan::class)->finalStage()])->assertNoContent();
    }

    /**
     * Add a variable to the production environment.
     *
     * @param  string  $key
     * @param  string  $value
     * @param  bool  $secret
     * @param  string  $scope
     * @return void
     */
    private function variable(string $key, string $value, bool $secret = true, string $scope = 'runtime'): void
    {
        $variable = new EnvironmentVariable;
        $variable->forceFill(['environment_id' => $this->production->id, 'key' => $key, 'value' => $value, 'is_secret' => $secret, 'scope' => $scope, 'current_version' => 1])->save();
    }

    /**
     * Send a signed GitHub pull-request event for pull request 12 from `feature/checkout` into `main`.
     *
     * @param  string  $delivery
     * @param  array<string, mixed>  $overrides  merged into the payload
     * @return TestResponse<Response>
     */
    private function pullRequest(string $delivery, array $overrides = []): TestResponse
    {
        $payload = array_replace_recursive([
            'action' => 'opened', 'number' => 12,
            'pull_request' => [
                'title' => 'New checkout',
                'head' => ['ref' => 'feature/checkout', 'sha' => self::REVISION, 'repo' => ['full_name' => 'acme/shop']],
                'base' => ['ref' => 'main', 'repo' => ['full_name' => 'acme/shop']],
            ],
        ], $overrides);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', "/api/repositories/{$this->source->id}/webhook", [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'pull_request', 'HTTP_X_GITHUB_DELIVERY' => $delivery, 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }
}
