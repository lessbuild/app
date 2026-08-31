<?php

namespace Tests\Feature;

use App\Jobs\Server\InitialiseServerJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Recipe;
use App\Models\Server;
use App\Models\User;
use App\Scripts\Server\RecipesScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecipeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_and_delete_a_recipe(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('recipes.store'), [
            'name' => 'Install fail2ban',
            'description' => 'Protect SSH from brute force attempts.',
            'script' => 'apt-get install -y fail2ban',
        ])->assertRedirect(route('recipes.index'));

        $recipe = Recipe::query()->sole();
        $this->assertTrue($recipe->user->is($user));
        $this->assertSame('apt-get install -y fail2ban', $recipe->script);
        $this->assertNotSame(
            'apt-get install -y fail2ban',
            Recipe::query()->toBase()->find($recipe->id)->script,
        );
        $this->assertStringNotContainsString(
            'apt-get install -y fail2ban',
            Recipe::query()->toBase()->find($recipe->id)->script,
        );
        $this->assertArrayNotHasKey('script', $recipe->toArray());

        $this->actingAs($user)->get(route('recipes.edit', $recipe))
            ->assertSuccessful()
            ->assertSee('apt-get install -y fail2ban');

        $this->actingAs($user)->patch(route('recipes.update', $recipe), [
            'name' => 'Install and enable fail2ban',
            'description' => 'Protect SSH.',
            'script' => "apt-get install -y fail2ban\nsystemctl enable fail2ban",
        ])->assertRedirect(route('recipes.index'));

        $this->assertSame('Install and enable fail2ban', $recipe->fresh()->name);
        $this->assertSame(
            "apt-get install -y fail2ban\nsystemctl enable fail2ban",
            $recipe->fresh()->script,
        );
        $this->assertStringNotContainsString(
            'systemctl enable fail2ban',
            Recipe::query()->toBase()->find($recipe->id)->script,
        );

        $this->actingAs($user)->delete(route('recipes.destroy', $recipe))
            ->assertRedirect(route('recipes.index'));
        $this->assertDatabaseMissing('recipes', ['id' => $recipe->id]);
    }

    public function test_recipe_management_is_isolated_by_user(): void
    {
        [$owner, $intruder] = User::factory()->count(2)->create();
        $recipe = $owner->recipes()->create([
            'name' => 'Owner recipe',
            'description' => null,
            'script' => 'echo owner',
        ]);
        $intruderRecipe = $intruder->recipes()->create([
            'name' => 'Intruder recipe',
            'description' => null,
            'script' => 'echo intruder',
        ]);

        $this->actingAs($intruder)->get(route('recipes.index'))
            ->assertSuccessful()
            ->assertSee('Intruder recipe')
            ->assertDontSee('Owner recipe');
        $this->actingAs($intruder)->get(route('recipes.edit', $recipe))->assertForbidden();
        $this->actingAs($intruder)->patch(route('recipes.update', $recipe), [
            'name' => 'Changed',
            'script' => 'echo changed',
        ])->assertForbidden();
        $this->actingAs($intruder)->delete(route('recipes.destroy', $recipe))->assertForbidden();

        $this->assertSame('Owner recipe', $recipe->fresh()->name);
        $this->assertSame('Intruder recipe', $intruderRecipe->fresh()->name);
    }

    public function test_recipe_input_is_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('recipes.store'), [
            'name' => '',
            'description' => str_repeat('x', 1001),
            'script' => '',
        ])->assertSessionHasErrors(['name', 'description', 'script']);

        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_selected_recipes_are_embedded_in_server_provisioning_in_order(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => 'digitalocean',
            'token' => 'provider-secret',
            'description' => 'Cloud provider',
        ]);
        $first = $user->recipes()->create([
            'name' => 'First recipe',
            'description' => null,
            'script' => 'echo first-recipe',
        ]);
        $second = $user->recipes()->create([
            'name' => 'Second recipe',
            'description' => null,
            'script' => 'echo second-recipe',
        ]);

        Http::fake([
            'https://api.digitalocean.com/v2/account/keys' => Http::response([
                'ssh_key' => ['fingerprint' => 'fingerprint-123'],
            ], 201),
            'https://api.digitalocean.com/v2/droplets' => Http::response([
                'droplet' => [
                    'id' => 12345,
                    'name' => 'recipe-server',
                    'region' => ['name' => 'New York'],
                    'size' => ['slug' => 's-1vcpu-1gb'],
                    'image' => ['name' => 'Ubuntu 22.04'],
                ],
            ], 202),
        ]);

        $this->actingAs($user)->post(route('servers.store'), [
            'provider_id' => $provider->id,
            'type' => ServerTypeEnum::worker->value,
            'name' => 'Recipe Server',
            'region' => 'nyc1',
            'image' => 'ubuntu-22-04-x64',
            'size' => 's-1vcpu-1gb',
            'recipes' => [$second->id, $first->id],
        ])->assertRedirect();

        $server = Server::query()->sole();
        $this->assertSame(ServerTypeEnum::worker, $server->type);
        $this->assertSame([$second->id, $first->id], $server->recipes->pluck('id')->all());
        $this->assertSame(
            ['Second recipe', 'First recipe'],
            $server->recipe_snapshot === null ? [] : array_column($server->recipe_snapshot, 'name'),
        );
        $rawSnapshot = Server::query()->toBase()->find($server->id)->recipe_snapshot;
        $this->assertStringNotContainsString('echo second-recipe', $rawSnapshot);
        $this->assertStringNotContainsString('echo first-recipe', $rawSnapshot);
        $this->assertArrayNotHasKey('recipe_snapshot', $server->toArray());
        Queue::assertPushed(InitialiseServerJob::class);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.digitalocean.com/v2/droplets') {
                return false;
            }

            $script = $request['user_data'];

            return str_contains($script, 'echo second-recipe')
                && str_contains($script, 'echo first-recipe')
                && strpos($script, 'echo second-recipe') < strpos($script, 'echo first-recipe');
        });
    }

    public function test_user_cannot_attach_another_users_recipe_to_a_server(): void
    {
        [$owner, $intruder] = User::factory()->count(2)->create();
        $provider = $intruder->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => 'digitalocean',
            'token' => 'provider-secret',
            'description' => 'Cloud provider',
        ]);
        $recipe = $owner->recipes()->create([
            'name' => 'Private recipe',
            'description' => null,
            'script' => 'echo secret',
        ]);

        $this->actingAs($intruder)->post(route('servers.store'), [
            'provider_id' => $provider->id,
            'type' => ServerTypeEnum::app->value,
            'name' => 'Invalid Server',
            'region' => 'nyc1',
            'image' => 'ubuntu-22-04-x64',
            'size' => 's-1vcpu-1gb',
            'recipes' => [$recipe->id],
        ])->assertSessionHasErrors(['recipes.0']);

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_recipe_script_isolated_and_advances_provisioning_without_recipes(): void
    {
        $user = User::factory()->create();
        $server = $user->servers()->create(['name' => 'Server']);
        $recipe = $user->recipes()->create([
            'name' => "Monitoring ' agent",
            'description' => null,
            'script' => "cd /tmp\necho installed",
        ]);
        $server->recipes()->attach($recipe, ['position' => 0]);

        $script = (new RecipesScript)->script(11, $server);

        $this->assertStringContainsString("printf '%s\\n' 'Running recipe: Monitoring '\\'' agent'", $script);
        $this->assertStringContainsString("(\n  set -Eeuo pipefail\ncd /tmp\necho installed\n)", $script);
        $this->assertStringEndsWith("provisionPing {$server->id} 11", $script);

        $emptyServer = $user->servers()->create(['name' => 'Empty']);
        $this->assertSame(
            "provisionPing {$emptyServer->id} 11",
            (new RecipesScript)->script(11, $emptyServer),
        );
    }

    public function test_server_snapshot_survives_recipe_edit_and_deletion(): void
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => 'digitalocean',
            'token' => 'provider-secret',
            'description' => 'Cloud provider',
        ]);
        $server = $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => 'Stable Server',
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $recipe = $user->recipes()->create([
            'name' => 'Original Recipe',
            'description' => 'Original description',
            'script' => 'echo original-command',
        ]);
        $server->recipes()->attach($recipe, ['position' => 0]);
        $server->captureProvisioningRecipes();

        $recipe->update([
            'name' => 'Changed Recipe',
            'description' => 'Changed description',
            'script' => 'echo changed-command',
        ]);
        $recipe->delete();
        $server->refresh();

        $script = (new RecipesScript)->script(11, $server);
        $this->assertStringContainsString('Running recipe: Original Recipe', $script);
        $this->assertStringContainsString('echo original-command', $script);
        $this->assertStringNotContainsString('Changed Recipe', $script);
        $this->assertStringNotContainsString('echo changed-command', $script);
        $this->assertDatabaseCount('recipe_server', 0);

        $this->actingAs($user)->get(route('servers.show', $server))
            ->assertSuccessful()
            ->assertSee('Original Recipe')
            ->assertSee('Original description')
            ->assertDontSee('Changed Recipe')
            ->assertDontSee('Changed description');
    }

    public function test_an_explicit_empty_snapshot_does_not_pick_up_recipes_attached_later(): void
    {
        $user = User::factory()->create();
        $server = $user->servers()->create(['name' => 'Empty Snapshot']);
        $server->captureProvisioningRecipes();
        $recipe = $user->recipes()->create([
            'name' => 'Later Recipe',
            'description' => null,
            'script' => 'echo later-command',
        ]);
        $server->recipes()->attach($recipe, ['position' => 0]);

        $this->assertSame(
            "provisionPing {$server->id} 11",
            (new RecipesScript)->script(11, $server->fresh()),
        );
    }
}
