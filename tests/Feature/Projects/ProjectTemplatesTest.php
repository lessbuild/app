<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\ProviderType;
use App\Enums\ServerType;
use App\Models\AnalyticsSite;
use App\Models\Project;
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
}
