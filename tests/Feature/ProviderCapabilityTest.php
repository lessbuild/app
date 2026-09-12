<?php

namespace Tests\Feature;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_form_only_offers_cloud_providers(): void
    {
        [$user, $github, $digitalOcean] = $this->providers();

        $this->actingAs($user)->get(route('servers.create'))
            ->assertSuccessful()
            ->assertSee($digitalOcean->name)
            ->assertDontSee($github->name);
    }

    public function test_provider_form_only_offers_supported_types_and_preserves_edit_selection(): void
    {
        [$user, $github] = $this->providers();

        $this->actingAs($user)->get(route('providers.create'))
            ->assertSuccessful()
            ->assertSee('digitalocean')
            ->assertSee('hetzner')
            ->assertSee('vultr')
            ->assertSee('github')
            ->assertSee('gitlab')
            ->assertSee('bitbucket')
            ->assertDontSee('linode');

        $response = $this->actingAs($user)->get(route('providers.edit', $github))->assertSuccessful();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $selected = (new \DOMXPath($document))->query('//input[@name="provider" and @checked]/@value');
        $this->assertCount(1, $selected);
        $this->assertSame('github', $selected->item(0)->nodeValue);
    }

    public function test_native_provider_form_submission_creates_an_encrypted_connection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('providers.store'), [
            'provider' => 'digitalocean',
            'name' => 'Disposable connection',
            'description' => 'Provider form regression',
            'token' => 'fixture-private-token',
            'connection_monitoring_enabled' => '0',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $provider = $user->workspaceProviders()->sole();
        $this->assertSame('digitalocean', $provider->provider);
        $this->assertSame('fixture-private-token', $provider->token);
        $this->assertNotSame('fixture-private-token', $provider->getRawOriginal('token'));
    }

    public function test_repository_forms_only_offer_source_control_providers(): void
    {
        [$user, $github, $digitalOcean] = $this->providers();
        [, , $repository] = $this->repositoryResources($user, $github, $digitalOcean);

        $this->actingAs($user)->get(route('repositories.create'))
            ->assertSuccessful()
            ->assertSee($github->name)
            ->assertDontSee($digitalOcean->name);

        $this->actingAs($user)->get(route('repositories.edit', $repository))
            ->assertSuccessful()
            ->assertSee($github->name)
            ->assertDontSee($digitalOcean->name);
    }

    public function test_server_creation_rejects_a_source_control_provider(): void
    {
        [$user, $github] = $this->providers();

        $this->actingAs($user)->post(route('servers.store'), [
            'provider_id' => $github->id,
            'type' => ServerTypeEnum::app->value,
            'name' => 'Wrong Provider',
            'region' => 'nyc1',
            'image' => 'ubuntu-22-04-x64',
            'size' => 's-1vcpu-1gb',
        ])->assertSessionHasErrors(['provider_id']);

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_repository_creation_and_update_reject_a_cloud_provider(): void
    {
        [$user, $github, $digitalOcean] = $this->providers();
        [$server, $website, $repository] = $this->repositoryResources($user, $github, $digitalOcean);

        $this->actingAs($user)->post(route('repositories.store'), [
            'provider_id' => $digitalOcean->id,
            'website_id' => $website->id,
            'name' => 'Invalid repository',
            'url' => 'github.com/example/invalid.git',
            'description' => 'Invalid provider',
        ])->assertSessionHasErrors(['provider_id']);

        $this->actingAs($user)->patch(route('repositories.update', $repository), [
            'provider_id' => $digitalOcean->id,
            'website_id' => $website->id,
            'name' => 'Changed',
            'url' => $repository->url,
            'description' => $repository->description,
        ])->assertSessionHasErrors(['provider_id']);

        $this->assertSame($github->id, $repository->fresh()->provider_id);
        $this->assertTrue($repository->fresh()->website->server->is($server));
    }

    public function test_provider_in_use_cannot_change_type_or_be_deleted(): void
    {
        [$user, $github, $digitalOcean] = $this->providers();
        $this->repositoryResources($user, $github, $digitalOcean);

        $this->actingAs($user)->patch(route('providers.update', $digitalOcean), [
            'provider' => Provider::TYPE_GITHUB,
            'name' => $digitalOcean->name,
            'description' => $digitalOcean->description,
            'token' => '',
        ])->assertSessionHasErrors(['provider']);
        $this->assertSame(Provider::TYPE_DIGITALOCEAN, $digitalOcean->fresh()->provider);

        $this->actingAs($user)->delete(route('providers.destroy', $digitalOcean))
            ->assertSessionHasErrors(['provider']);
        $this->assertNull($digitalOcean->fresh()->deleted_at);

        $this->actingAs($user)->delete(route('providers.destroy', $github))
            ->assertSessionHasErrors(['provider']);
        $this->assertNull($github->fresh()->deleted_at);
    }

    public function test_unused_provider_can_change_type_and_be_deleted(): void
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'Unused',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'secret',
            'description' => 'Unused provider',
        ]);

        $this->actingAs($user)->patch(route('providers.update', $provider), [
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'name' => 'Unused Cloud',
            'description' => 'Unused provider',
            'token' => '',
        ])->assertRedirect(route('providers.show', $provider));
        $this->assertSame(Provider::TYPE_DIGITALOCEAN, $provider->fresh()->provider);

        $this->actingAs($user)->delete(route('providers.destroy', $provider))
            ->assertRedirect(route('providers.index'));
        $this->assertSoftDeleted($provider);
    }

    public function test_provider_update_requires_workspace_management_without_writing(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'Owned provider',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'original-secret',
            'description' => 'Original description',
        ]);
        $organization = $owner->currentOrganization;
        $organization->members()->attach($operator, ['role' => 'operator']);
        $operator->update(['current_organization_id' => $organization->id]);

        $this->actingAs($operator)->patch(route('providers.update', $provider), [
            'provider' => Provider::TYPE_GITHUB,
            'name' => 'Changed provider',
            'description' => 'Changed description',
            'token' => 'replacement-secret',
            'connection_monitoring_enabled' => '0',
        ])->assertForbidden();

        $this->assertSame('Owned provider', $provider->fresh()->name);
        $this->assertSame('original-secret', $provider->fresh()->token);
    }

    private function providers(): array
    {
        $user = User::factory()->create();
        $github = $user->providers()->create([
            'name' => 'GitHub Source Control',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'github-secret',
            'description' => 'Source control',
        ]);
        $digitalOcean = $user->providers()->create([
            'name' => 'DigitalOcean Cloud',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'cloud-secret',
            'description' => 'Cloud infrastructure',
        ]);

        return [$user, $github, $digitalOcean];
    }

    private function repositoryResources(User $user, Provider $github, Provider $digitalOcean): array
    {
        $server = $user->servers()->create([
            'name' => 'Production',
            'provider_id' => $digitalOcean->id,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Application website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $user->repositories()->create([
            'provider_id' => $github->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'description' => 'Application source',
        ]);

        return [$server, $website, $repository];
    }
}
