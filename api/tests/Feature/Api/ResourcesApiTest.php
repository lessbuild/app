<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\ApiScope;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ResourcesApiTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Make a token for a person in a project's account.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  list<ApiScope>  $scopes
     * @return string
     */
    private function token(User $user, Project $project, array $scopes): string
    {
        return app(CreateApiToken::class)->handle($user, $project->account, new CreateApiTokenData('terraform', $scopes, 30))->plainText;
    }

    /**
     * Check projects are created with their services, read, changed (services synced) and deleted, within the token's
     * account and scopes.
     *
     * @return void
     */
    public function test_projects_are_managed_as_resources(): void
    {
        $home = Project::factory()->create();
        $owner = $this->ownerOf($home);
        $other = Project::factory()->create(['name' => 'Not yours']);
        $token = $this->token($owner, $home, [ApiScope::ProjectsRead, ApiScope::ProjectsWrite]);
        $readOnly = $this->token($owner, $home, [ApiScope::ProjectsRead]);

        $this->withToken($readOnly)->postJson('/api/v2/projects', ['name' => 'Shop'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v2/projects', ['name' => 'Shop', 'services' => ['nope']])->assertUnprocessable()->assertJsonValidationErrors('services.0');
        $created = $this->withToken($token)->postJson('/api/v2/projects', ['name' => 'Shop', 'description' => 'The store', 'services' => ['monitoring', 'deploy']])
            ->assertCreated()->assertJsonPath('data.name', 'Shop')->assertJsonPath('data.services', ['deploy', 'monitoring'])->assertJsonPath('data.environments.0.slug', 'production');
        $id = (string) $created->json('data.id');

        $this->withToken($token)->getJson('/api/v2/projects')->assertOk()->assertJsonMissing(['name' => 'Not yours']);
        $this->withToken($token)->getJson("/api/v2/projects/{$other->id}")->assertNotFound();
        $this->withToken($token)->putJson("/api/v2/projects/{$id}", ['name' => 'Shop EU', 'services' => ['monitoring', 'analytics']])
            ->assertOk()->assertJsonPath('data.name', 'Shop EU')->assertJsonPath('data.services', ['analytics', 'monitoring']);
        $this->withToken($token)->putJson("/api/v2/projects/{$id}", ['name' => 'Shop EU'])->assertOk()->assertJsonPath('data.services', ['analytics', 'monitoring']);
        $this->withToken($token)->deleteJson("/api/v2/projects/{$id}")->assertNoContent();
        $this->assertNull(Project::query()->find($id));
    }

    /**
     * Check servers and websites are created, read, changed and deleted through the API, and other accounts' records
     * stay invisible.
     *
     * @return void
     */
    public function test_servers_and_websites_are_managed_as_resources(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $this->onTier($project, 'deploy', 'pro');
        $provider = Provider::factory()->create(['account_id' => $project->account_id]);
        $foreign = Server::factory()->create();
        $token = $this->token($owner, $project, [ApiScope::InfrastructureRead, ApiScope::InfrastructureWrite]);

        $this->withToken($token)->postJson('/api/v2/servers', ['provider_id' => $provider->id, 'type' => 'app', 'name' => 'web 1', 'region' => 'fra1', 'size' => 's-1vcpu-1gb', 'image' => 'ubuntu-24-04-x64'])
            ->assertCreated()->assertJsonPath('data.name', 'web-1')->assertJsonMissingPath('data.mysql_root_password');
        $server = Server::query()->where('account_id', $project->account_id)->sole();
        $this->withToken($token)->getJson("/api/v2/servers/{$server->id}")->assertOk()->assertJsonPath('data.public_ip', '203.0.113.50')->assertJsonPath('data.status', Server::STATUS_PROVISIONING);
        $this->withToken($token)->getJson("/api/v2/servers/{$foreign->id}")->assertNotFound();
        $this->withToken($token)->getJson('/api/v2/servers')->assertOk()->assertJsonCount(1, 'data');

        $server->forceFill(['provisioning_status' => Server::STATUS_ACTIVE])->save();
        $website = $this->withToken($token)->postJson('/api/v2/websites', ['name' => 'Shop', 'server_id' => $server->id, 'url' => 'shop.example.com'])
            ->assertCreated()->assertJsonPath('data.url', 'shop.example.com')->assertJsonPath('data.server_id', $server->id);
        $websiteId = (int) $website->json('data.id');
        Website::query()->whereKey($websiteId)->update(['provisioning_status' => 'active']);
        $this->withToken($token)->putJson("/api/v2/websites/{$websiteId}", ['name' => 'Shop EU', 'server_id' => $server->id, 'url' => 'shop.example.com', 'release_retention' => 4])
            ->assertOk()->assertJsonPath('data.name', 'Shop EU')->assertJsonPath('data.release_retention', 4);
        $this->withToken($token)->getJson('/api/v2/websites')->assertOk()->assertJsonPath('data.0.id', $websiteId);
        $this->withToken($token)->deleteJson("/api/v2/websites/{$websiteId}")->assertNoContent();
        $this->withToken($token)->deleteJson("/api/v2/servers/{$server->id}")->assertNoContent();
        $this->withToken($token)->deleteJson("/api/v2/servers/{$foreign->id}")->assertNotFound();
    }

    /**
     * Check a project's monitors are created, changed (with or without the version read), read and archived.
     *
     * @return void
     */
    public function test_monitors_are_managed_as_resources(): void
    {
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $token = $this->token($owner, $project, [ApiScope::MonitoringRead, ApiScope::MonitoringWrite]);
        $fields = ['name' => 'Home page', 'environment_id' => $production->id, 'check_type' => 'http', 'request_url' => 'https://shop.example.com/',
            'method' => 'GET', 'status_min' => 200, 'status_max' => 399, 'timeout_seconds' => 10, 'interval_minutes' => 5, 'trigger_checks' => 2, 'recovery_checks' => 2, 'enabled' => true];
        $base = "/api/v2/projects/{$project->id}/monitors";

        $this->withToken($token)->postJson($base, [...$fields, 'request_url' => ''])->assertUnprocessable()->assertJsonValidationErrors('request_url');
        $id = (int) $this->withToken($token)->postJson($base, $fields)->assertCreated()->assertJsonPath('data.check_type', 'http')->assertJsonPath('data.version', 0)->json('data.id');
        $this->withToken($token)->putJson("{$base}/{$id}", [...$fields, 'name' => 'Home', 'interval_minutes' => 1])->assertOk()->assertJsonPath('data.name', 'Home')->assertJsonPath('data.version', 1);
        $this->withToken($token)->putJson("{$base}/{$id}", [...$fields, 'version' => 0])->assertStatus(409);
        $this->withToken($token)->getJson($base)->assertOk()->assertJsonPath('data.0.name', 'Home');
        $this->withToken($token)->getJson("{$base}/{$id}")->assertOk()->assertJsonPath('data.request_url', 'https://shop.example.com/');
        $this->withToken($token)->deleteJson("{$base}/{$id}")->assertNoContent();
        $this->assertSoftDeleted('monitors', ['id' => $id]);
    }
}
