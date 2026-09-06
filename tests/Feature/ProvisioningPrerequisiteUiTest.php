<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisioningPrerequisiteUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_creation_explains_and_enforces_its_cloud_provider_prerequisite(): void
    {
        $this->actingAs(User::factory()->create())->get(route('servers.create'))
            ->assertSuccessful()
            ->assertSee('You must add a cloud provider before you can add a server')
            ->assertSee('Add cloud provider')
            ->assertSee(route('providers.create'))
            ->assertSee('id="provider_id"', false)
            ->assertSee('id="type"', false)
            ->assertSee('id="image"', false)
            ->assertSee('id="region"', false)
            ->assertSee('id="size"', false)
            ->assertSee('disabled', false);
    }

    public function test_website_creation_links_its_server_label_and_disables_submission_without_a_server(): void
    {
        $this->actingAs(User::factory()->create())->get(route('websites.create'))
            ->assertSuccessful()
            ->assertSee('You need an active application server with MySQL before you can add a website')
            ->assertSee('for="server_id"', false)
            ->assertSee('id="server_id"', false)
            ->assertSee('disabled', false);
    }

    public function test_repository_creation_names_both_prerequisites_and_links_select_labels(): void
    {
        $this->actingAs(User::factory()->create())->get(route('repositories.create'))
            ->assertSuccessful()
            ->assertSee('You must add a source control provider before you can add a repository')
            ->assertSee('You need an active website before you can add a repository')
            ->assertSee('for="provider_id"', false)
            ->assertSee('id="provider_id"', false)
            ->assertSee('for="website_id"', false)
            ->assertSee('id="website_id"', false)
            ->assertSee('disabled', false);
    }
}
