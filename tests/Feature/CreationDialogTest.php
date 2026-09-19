<?php

namespace Tests\Feature;

use App\Models\User;
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
}
