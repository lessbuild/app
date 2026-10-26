<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\EnvironmentKind;
use App\Models\Account;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\TestCase;

final class ProjectDeletionTeardownTest extends TestCase
{
    use InfrastructureHelpers;
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    /**
     * Set up an owner, an account and faked cloud/SSH infrastructure.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->owner = User::factory()->create();
        $this->account = Account::factory()->withMember($this->owner)->create(['name' => 'Acme']);
    }

    /**
     * Give the account a project with a production environment, a server and a website on it.
     *
     * @param  string  $name
     * @return array{0: Project, 1: Server, 2: Website}
     */
    private function projectWithServer(string $name): array
    {
        $project = Project::factory()->for($this->account)->create(['name' => $name]);
        $environment = Environment::factory()->for($project)->create(['kind' => EnvironmentKind::Production, 'name' => 'Production']);
        $provider = Provider::factory()->create(['account_id' => $this->account->id]);
        $server = Server::factory()->create(['account_id' => $this->account->id, 'provider_id' => $provider->id, 'identifier' => (string) fake()->unique()->numberBetween(100000, 999999)]);
        $website = Website::factory()->create(['account_id' => $this->account->id, 'server_id' => $server->id, 'environment_id' => $environment->id]);

        return [$project, $server, $website];
    }

    /**
     * Deleting a project destroys the servers and websites only it uses, lists them first on the settings page, and
     * leaves everything in place when a server can't be destroyed.
     *
     * @return void
     */
    public function test_deleting_a_project_tears_down_its_own_infrastructure(): void
    {
        [$project, $server, $website] = $this->projectWithServer('Payeio');
        $base = "/api/app/projects/{$project->id}";
        $confirmed = ['auth.password_confirmed_at' => PHP_INT_MAX];

        // The settings page warns which server and website will go.
        $this->actingAs($this->owner)->getJson("{$base}/settings")
            ->assertOk()
            ->assertJsonPath('teardown.servers.0.name', $server->label())
            ->assertJsonPath('teardown.websites.0.url', $website->url);

        // When the provider refuses to destroy the server, nothing is deleted.
        $this->cloud->failDelete = true;
        $this->actingAs($this->owner)->withSession($confirmed)->deleteJson($base, ['confirm_name' => 'Payeio'])->assertUnprocessable();
        $this->assertNotNull($project->fresh());
        $this->assertNotNull($server->fresh());
        $this->assertNotNull($website->fresh());

        // When it succeeds, the droplet is destroyed at the provider and the project, server and website all go.
        $this->cloud->failDelete = false;
        $this->actingAs($this->owner)->withSession($confirmed)->deleteJson($base, ['confirm_name' => 'Payeio'])
            ->assertOk()
            ->assertJsonPath('redirect', '/dashboard');
        $this->assertNull($project->fresh());
        $this->assertNull($server->fresh());
        $this->assertSoftDeleted($website);
        $this->assertContains($server->identifier, $this->cloud->deletedServers);
    }

    /**
     * A server shared with another project is kept when one of the projects is deleted; only that project's own
     * website goes.
     *
     * @return void
     */
    public function test_a_shared_server_is_not_destroyed(): void
    {
        [$project, $server, $website] = $this->projectWithServer('Payeio');
        $other = Project::factory()->for($this->account)->create(['name' => 'Vacenly']);
        $otherEnvironment = Environment::factory()->for($other)->create(['kind' => EnvironmentKind::Production, 'name' => 'Production']);
        $otherWebsite = Website::factory()->create(['account_id' => $this->account->id, 'server_id' => $server->id, 'environment_id' => $otherEnvironment->id]);
        $base = "/api/app/projects/{$project->id}";

        $this->actingAs($this->owner)->getJson("{$base}/settings")->assertOk()->assertJsonPath('teardown.servers', []);

        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => PHP_INT_MAX])->deleteJson($base, ['confirm_name' => 'Payeio'])->assertOk();

        $this->assertNull($project->fresh());
        $this->assertSoftDeleted($website);
        $this->assertNotNull($server->fresh());
        $this->assertNotSoftDeleted($otherWebsite);
        $this->assertNotContains($server->identifier, $this->cloud->deletedServers);
    }
}
