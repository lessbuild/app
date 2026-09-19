<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreationDialogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_websites_inventory_hosts_the_website_creation_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('websites.index', ['dialog' => 'create-website']);

        $this->actingAs($user)
            ->get($dialogUrl)
            ->assertOk()
            ->assertSee('id="website-create-dialog"', false)
            ->assertSee('data-modal-trigger="website-create-dialog"', false)
            ->assertSee('action="'.route('websites.store', ['dialog' => 'create-website']).'"', false);
    }

    public function test_website_creation_validation_returns_to_the_open_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('websites.index', ['dialog' => 'create-website']);

        $this->actingAs($user)
            ->from($dialogUrl)
            ->post(route('websites.store', ['dialog' => 'create-website']), [])
            ->assertRedirect($dialogUrl)
            ->assertSessionHasErrors(['name', 'server_id', 'url', 'description', 'environment']);
    }

    public function test_the_servers_inventory_hosts_the_server_creation_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('servers.index', ['dialog' => 'create-server']);

        $this->actingAs($user)
            ->get($dialogUrl)
            ->assertOk()
            ->assertSee('id="server-create-dialog"', false)
            ->assertSee('data-modal-trigger="server-create-dialog"', false)
            ->assertSee('action="'.route('servers.store', ['dialog' => 'create-server']).'"', false);
    }

    public function test_server_creation_validation_returns_to_the_open_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('servers.index', ['dialog' => 'create-server']);

        $this->actingAs($user)
            ->from($dialogUrl)
            ->post(route('servers.store', ['dialog' => 'create-server']), [])
            ->assertRedirect($dialogUrl)
            ->assertSessionHasErrors(['provider_id', 'name', 'region', 'image', 'size']);
    }

    public function test_the_applications_inventory_hosts_the_application_creation_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('projects.index', ['dialog' => 'create-application']);

        $this->actingAs($user)
            ->get($dialogUrl)
            ->assertOk()
            ->assertSee('id="application-create-dialog"', false)
            ->assertSee('data-modal-trigger="application-create-dialog"', false)
            ->assertSee('action="'.route('projects.store', ['dialog' => 'create-application']).'"', false)
            ->assertSee('Curated template 1.0.0');
    }

    public function test_application_creation_validation_returns_to_the_open_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('projects.index', ['dialog' => 'create-application']);

        $this->actingAs($user)
            ->from($dialogUrl)
            ->post(route('projects.store', ['dialog' => 'create-application']), [])
            ->assertRedirect($dialogUrl)
            ->assertSessionHasErrors('name');
    }

    public function test_the_repositories_inventory_hosts_the_repository_creation_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('repositories.index', ['dialog' => 'create-repository']);

        $this->actingAs($user)
            ->get($dialogUrl)
            ->assertOk()
            ->assertSee('id="repository-create-dialog"', false)
            ->assertSee('data-modal-trigger="repository-create-dialog"', false)
            ->assertSee('action="'.route('repositories.store', ['dialog' => 'create-repository']).'"', false)
            ->assertSee('for="repository-create-provider_id"', false)
            ->assertSee('for="repository-create-website_id"', false);
    }

    public function test_repository_creation_validation_returns_to_the_open_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('repositories.index', ['dialog' => 'create-repository']);

        $this->actingAs($user)
            ->from($dialogUrl)
            ->post(route('repositories.store', ['dialog' => 'create-repository']), [])
            ->assertRedirect($dialogUrl)
            ->assertSessionHasErrors(['provider_id', 'website_id', 'name', 'url', 'description']);
    }

    public function test_the_provider_inventory_hosts_the_provider_creation_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('providers.index', ['dialog' => 'create-provider']);

        $this->actingAs($user)
            ->get($dialogUrl)
            ->assertOk()
            ->assertSee('id="provider-create-dialog"', false)
            ->assertSee('data-modal-trigger="provider-create-dialog"', false)
            ->assertSee('action="'.route('providers.store', ['dialog' => 'create-provider']).'"', false)
            ->assertSee('id="provider-create-token"', false);
    }

    public function test_provider_creation_validation_returns_to_the_open_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('providers.index', ['dialog' => 'create-provider']);

        $this->actingAs($user)
            ->from($dialogUrl)
            ->post(route('providers.store', ['dialog' => 'create-provider']), [])
            ->assertRedirect($dialogUrl)
            ->assertSessionHasErrors(['provider', 'token', 'name', 'description']);
    }

    public function test_the_provider_show_page_hosts_its_edit_dialog_when_requested(): void
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Source provider',
        ]);
        $dialogUrl = route('providers.show', ['provider' => $provider, 'dialog' => 'edit-provider']);

        $this->actingAs($user)
            ->get($dialogUrl)
            ->assertOk()
            ->assertSee('id="provider-edit-dialog"', false)
            ->assertSee('data-modal-trigger="provider-edit-dialog"', false)
            ->assertSee('action="'.route('providers.update', ['provider' => $provider, 'dialog' => 'edit-provider']).'"', false)
            ->assertSee('id="provider-edit-token"', false);
    }

    public function test_the_repository_show_page_hosts_its_edit_dialog_when_requested(): void
    {
        [$user, $repository] = $this->repository();
        $dialogUrl = route('repositories.show', ['repository' => $repository, 'dialog' => 'edit-repository']);

        $this->actingAs($user)
            ->get($dialogUrl)
            ->assertOk()
            ->assertSee('id="repository-edit-dialog"', false)
            ->assertSee('data-modal-trigger="repository-edit-dialog"', false)
            ->assertSee('action="'.route('repositories.update', ['repository' => $repository, 'dialog' => 'edit-repository']).'"', false)
            ->assertSee('for="repository-edit-provider_id"', false);
    }

    public function test_the_website_show_page_hosts_its_edit_dialog_when_requested(): void
    {
        [$user, $repository] = $this->repository();
        $website = $repository->website;
        $dialogUrl = route('websites.show', ['website' => $website, 'dialog' => 'edit-website']);

        $this->actingAs($user)
            ->get(route('websites.show', $website))
            ->assertOk()
            ->assertDontSee('id="website-edit-dialog"', false)
            ->assertDontSee('APP_ENV=production');

        $this->actingAs($user)
            ->get($dialogUrl)
            ->assertOk()
            ->assertSee('id="website-edit-dialog"', false)
            ->assertSee('data-modal-trigger="website-edit-dialog"', false)
            ->assertSee('action="'.route('websites.update', ['website' => $website, 'dialog' => 'edit-website']).'"', false)
            ->assertSee('id="website-edit-environment"', false)
            ->assertSee('APP_ENV=production');
    }

    public function test_the_recipe_inventory_hosts_create_and_selected_edit_dialogs(): void
    {
        $user = User::factory()->create();
        $recipe = $user->recipes()->create([
            'name' => 'Install monitoring',
            'description' => 'Install the monitoring agent.',
            'script' => 'echo install-monitoring',
        ]);

        $this->actingAs($user)
            ->get(route('recipes.index', ['dialog' => 'create-recipe']))
            ->assertOk()
            ->assertSee('id="recipe-create-dialog"', false)
            ->assertSee('data-modal-trigger="recipe-create-dialog"', false)
            ->assertSee('action="'.route('recipes.store', ['dialog' => 'create-recipe']).'"', false)
            ->assertSee('id="recipe-create-script"', false)
            ->assertDontSee('echo install-monitoring');

        $this->actingAs($user)
            ->get(route('recipes.index', ['dialog' => 'edit-recipe-'.$recipe->id]))
            ->assertOk()
            ->assertSee('id="recipe-edit-dialog-'.$recipe->id.'"', false)
            ->assertSee('action="'.route('recipes.update', ['recipe' => $recipe, 'dialog' => 'edit-recipe']).'"', false)
            ->assertSee('id="recipe-edit-script"', false)
            ->assertSee('echo install-monitoring');
    }

    public function test_recipe_creation_validation_returns_to_the_open_dialog(): void
    {
        $user = User::factory()->create();
        $dialogUrl = route('recipes.index', ['dialog' => 'create-recipe']);

        $this->actingAs($user)
            ->from($dialogUrl)
            ->post(route('recipes.store', ['dialog' => 'create-recipe']), [])
            ->assertRedirect($dialogUrl)
            ->assertSessionHasErrors(['name', 'script']);
    }

    /** @return array{0: User, 1: Repository} */
    private function repository(): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'github-secret',
            'description' => 'Source provider',
        ]);
        $server = $user->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Active Website',
            'description' => 'Active website',
            'environment' => 'APP_ENV=production',
            'url' => 'active.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return [$user, $repository];
    }
}
