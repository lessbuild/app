<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Actions\Projects\CreateEnvironment;
use App\Enums\EnvironmentKind;
use App\Enums\ProviderType;
use App\Enums\ServerType;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsSite;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ProjectTemplatesTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that the Laravel template sets up the whole project: services, runtime, website with its health check,
     * the repository with migrations before release, and an Analytics site.
     *
     * @return void
     */
    public function test_a_project_is_set_up_from_the_laravel_template(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $existing = Project::factory()->create();
        $owner = $this->ownerOf($existing);
        $owner->forceFill(['current_account_id' => $existing->account_id])->save();
        $this->onTier($existing, 'deploy', 'pro');
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $existing->account_id])->id, 'type' => ServerType::App, 'name' => 'web-1']);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $existing->account_id, 'name' => 'GitHub']);

        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('From a template');
        $this->actingAs($owner)->get('/projects/templates?template=laravel')->assertOk()->assertSee('Set up Laravel')->assertSee('web-1');
        $this->actingAs($owner)->post('/projects/templates', ['template' => 'rails', 'name' => 'Shop'])->assertSessionHasErrors('template');

        $this->actingAs($owner)->post('/projects/templates', [
            'template' => 'laravel', 'name' => 'Shop', 'server_id' => $server->id, 'domain' => 'Shop.Example.com',
            'provider_id' => $github->id, 'repository_url' => 'https://github.com/Acme/Shop.git', 'branch' => 'main',
        ])->assertRedirect();

        $project = Project::query()->where('name', 'Shop')->sole();
        $this->assertEqualsCanonicalizing(['deploy', 'infrastructure', 'monitoring', 'analytics'], $project->enabledServices()->pluck('service')->all());
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $this->assertSame(['php', 'npm run build --if-present', true], [$production->runtime_type, $production->build_command, $production->automatic_rollback]);
        $website = Website::query()->where('url', 'shop.example.com')->sole();
        $this->assertSame([$server->id, '/up', $production->id], [$website->server_id, $website->health_check_path, $website->environment_id]);
        $repository = Repository::query()->where('project_id', $project->id)->sole();
        $this->assertSame(['github.com/acme/shop', 'main', $website->id], [$repository->url, $repository->branch, $repository->website_id]);
        $this->assertStringContainsString('php artisan migrate --force', (string) $repository->build_commands);
        $this->assertSame(['shop.example.com'], AnalyticsSite::query()->where('project_id', $project->id)->sole()->domains);
    }

    /**
     * Check a project can be saved as a template (variable names without values, its other environments, release
     * settings, uptime checks on its own domain and goals) and a new project started from it on its own domain.
     *
     * @return void
     */
    public function test_a_project_is_saved_as_a_template_and_reused(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $existing = Project::factory()->create();
        $owner = $this->ownerOf($existing);
        $owner->forceFill(['current_account_id' => $existing->account_id])->save();
        $this->onTier($existing, 'deploy', 'pro');
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $existing->account_id])->id, 'type' => ServerType::App]);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $existing->account_id]);
        $this->actingAs($owner)->post('/projects/templates', [
            'template' => 'laravel', 'name' => 'Shop', 'server_id' => $server->id, 'domain' => 'shop.example.com',
            'provider_id' => $github->id, 'repository_url' => 'github.com/acme/shop', 'branch' => 'main',
        ])->assertRedirect();
        $shop = Project::query()->where('name', 'Shop')->sole();
        $production = $shop->environments()->where('slug', 'production')->firstOrFail();
        $production->forceFill(['deployment_strategy' => 'canary', 'requires_deployment_approval' => true])->save();
        app(CreateEnvironment::class)->handle($owner, $shop, 'Staging', EnvironmentKind::Staging);
        Website::query()->where('url', 'shop.example.com')->sole()->forceFill(['env_file' => "APP_KEY=base64:secret\n# comment\nDB_PASSWORD=hunter2\n"])->save();
        Monitor::factory()->create(['environment_id' => $production->id, 'type' => 'http', 'name' => 'Health', 'request_url' => 'https://shop.example.com/health?deep=1', 'interval_minutes' => 1]);
        Monitor::factory()->create(['environment_id' => $production->id, 'type' => 'http', 'name' => 'Payments', 'request_url' => 'https://api.stripe.com/healthcheck']);
        $site = AnalyticsSite::query()->where('project_id', $shop->id)->sole();
        (new AnalyticsGoal)->forceFill(['site_id' => $site->id, 'name' => 'Checkout', 'kind' => 'pageview', 'match_type' => 'exact', 'match_value' => '/thanks', 'active' => true])->save();

        $this->actingAs($owner)->post("/projects/{$shop->id}/template", ['name' => 'Shop setup'])->assertRedirect();
        $template = ProjectTemplate::query()->sole();
        $this->assertSame("APP_KEY=\nDB_PASSWORD=\n", $template->definition['env']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($template->definition));
        $this->assertCount(1, $template->definition['monitors']);
        $this->actingAs($owner)->get('/projects/templates')->assertOk()->assertSee('Shop setup')->assertSee('1 other environment · 1 uptime check · 1 goal');

        $this->actingAs($owner)->post('/projects/templates', [
            'template' => $template->key(), 'name' => 'Blog', 'server_id' => $server->id, 'domain' => 'blog.example.com',
            'provider_id' => $github->id, 'repository_url' => 'github.com/acme/blog', 'branch' => 'main',
        ])->assertRedirect();
        $blog = Project::query()->where('name', 'Blog')->sole();
        $blogProduction = $blog->environments()->where('slug', 'production')->firstOrFail();
        $this->assertSame(['canary', true], [$blogProduction->deployment_strategy, $blogProduction->requires_deployment_approval]);
        $this->assertTrue($blog->environments()->where('name', 'Staging')->exists());
        $this->assertSame('https://blog.example.com/health?deep=1', Monitor::query()->where('environment_id', $blogProduction->id)->where('name', 'Health')->sole()->request_url);
        $this->assertSame("APP_KEY=\nDB_PASSWORD=\n", Website::query()->where('url', 'blog.example.com')->sole()->env_file);
        $this->assertSame(['/thanks'], AnalyticsGoal::query()->whereHas('site', fn ($query) => $query->where('project_id', $blog->id))->pluck('match_value')->all());

        $this->actingAs($owner)->delete("/projects/templates/{$template->id}")->assertRedirect();
        $this->assertSame(0, ProjectTemplate::query()->count());
    }
}
