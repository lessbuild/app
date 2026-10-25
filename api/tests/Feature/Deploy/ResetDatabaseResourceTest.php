<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\AccountRole;
use App\Enums\ServerType;
use App\Models\EnvironmentResource;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ResetDatabaseResourceTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Server $server;

    private string $base;

    /**
     * A project with an active app server and its production environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $this->server = Server::factory()->create([
            'account_id' => $this->project->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id,
            'type' => ServerType::App, 'provisioning_status' => Server::STATUS_ACTIVE, 'mysql_root_password' => 'root-secret',
        ]);
        $this->base = "/api/app/projects/{$this->project->id}/deploy";
    }

    /**
     * Make a managed MySQL resource for a website on the server.
     *
     * @return array{0: \App\Models\Environment, 1: EnvironmentResource, 2: Website}
     */
    private function managedDatabase(): array
    {
        $environment = $this->project->environments()->firstOrFail();
        $website = Website::factory()->create(['account_id' => $this->project->account_id, 'server_id' => $this->server->id, 'environment_id' => $environment->id, 'deployment_slug' => 'api-payeio', 'url' => 'api.example.com']);
        $resource = new EnvironmentResource;
        $resource->forceFill(['environment_id' => $environment->id, 'name' => 'database', 'type' => 'mysql', 'is_managed' => true, 'configuration' => ['database_website_id' => $website->id]])->save();

        return [$environment, $resource, $website];
    }

    /**
     * Resetting a managed MySQL database drops its tables on the server, so the next deploy migrates from scratch.
     *
     * @return void
     */
    public function test_resetting_a_managed_database_drops_its_tables(): void
    {
        [$environment, $resource] = $this->managedDatabase();

        $this->actingAs($this->owner)->postJson("{$this->base}/environments/{$environment->id}/resources/{$resource->id}/reset")->assertOk();

        $command = (string) (collect($this->shell->ran)->last()['command'] ?? '');
        $this->assertStringContainsString('DROP TABLE', $command);
        $this->assertStringContainsString('api_payeio', $command, 'It targets the website’s database.');
        $this->assertSame($this->server->id, collect($this->shell->ran)->last()['server']);
    }

    /**
     * When the server command fails, the reset reports why and nothing is claimed.
     *
     * @return void
     */
    public function test_a_failed_reset_reports_the_reason(): void
    {
        [$environment, $resource] = $this->managedDatabase();
        $this->shell->reply('', 1, 'Access denied');

        $this->actingAs($this->owner)->postJson("{$this->base}/environments/{$environment->id}/resources/{$resource->id}/reset")
            ->assertUnprocessable()->assertJsonValidationErrors('resource');
    }

    /**
     * Only a managed database can be reset, and only by someone who can manage the environment's resources.
     *
     * @return void
     */
    public function test_reset_is_limited_to_managed_databases_and_managers(): void
    {
        $environment = $this->project->environments()->firstOrFail();
        $redis = new EnvironmentResource;
        $redis->forceFill(['environment_id' => $environment->id, 'name' => 'cache', 'type' => 'redis', 'is_managed' => true, 'configuration' => ['variables' => ['REDIS_HOST' => '127.0.0.1']]])->save();
        $this->actingAs($this->owner)->postJson("{$this->base}/environments/{$environment->id}/resources/{$redis->id}/reset")->assertUnprocessable()->assertJsonValidationErrors('resource');

        [, $resource] = $this->managedDatabase();
        $outsider = User::factory()->create();
        $other = Project::factory()->create(['account_id' => $this->project->account_id]);
        $this->addMember($this->project, $outsider, AccountRole::Member)->forceFill(['project_ids' => [$other->id]])->save();
        // A member limited to another project can't reach this project's environment at all.
        $this->actingAs($outsider)->postJson("{$this->base}/environments/{$environment->id}/resources/{$resource->id}/reset")->assertNotFound();
    }
}
