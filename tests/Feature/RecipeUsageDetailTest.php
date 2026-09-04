<?php

namespace Tests\Feature;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeUsageDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_review_recipe_assignments_and_status_metrics_without_rendering_the_script(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner, 'Deploy observability', 'recipe-script-secret');
        $provider = $this->provider($owner, 'Owner provider');
        $queued = $this->server($owner, $provider, 'Queued server', Server::STATUS_QUEUED);
        $failed = $this->server($owner, $provider, 'Failed server', Server::STATUS_FAILED);
        $ready = $this->server($owner, $provider, 'Ready server', Server::STATUS_ACTIVE, 'Customer-facing server');
        $recipe->servers()->attach([
            $queued->id => ['position' => 0],
            $failed->id => ['position' => 1],
            $ready->id => ['position' => 2],
        ]);

        $other = User::factory()->create();
        $foreignServer = $this->server(
            $other,
            $this->provider($other, 'Foreign provider'),
            'foreign-server-secret',
            Server::STATUS_ACTIVE,
        );
        $recipe->servers()->attach($foreignServer, ['position' => 3]);

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 3,
                'ready' => 1,
                'provisioning' => 1,
                'failed' => 1,
            ])
            ->assertSee('Provisioning plan snapshots')
            ->assertSee('Server assignments')
            ->assertSeeInOrder(['Queued server', 'Failed server', 'Customer-facing server'])
            ->assertSee('#1')
            ->assertSee('#2')
            ->assertSee('#3')
            ->assertSee(route('servers.show', $ready))
            ->assertDontSee('recipe-script-secret')
            ->assertDontSee('foreign-server-secret');
    }

    public function test_unused_recipe_has_an_explicit_empty_assignment_view(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner, 'Unused recipe', 'unused-script-secret');

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'ready' => 0,
                'provisioning' => 0,
                'failed' => 0,
            ])
            ->assertSee('No servers use this recipe')
            ->assertDontSee('unused-script-secret');
    }

    public function test_recipe_usage_is_private_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner, 'Private recipe', 'private-script-secret');

        $this->get(route('recipes.show', $recipe))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('recipes.show', $recipe))
            ->assertForbidden();
    }

    private function recipe(User $user, string $name, string $script): Recipe
    {
        return $user->recipes()->create([
            'name' => $name,
            'description' => "{$name} description",
            'script' => $script,
        ]);
    }

    private function provider(User $user, string $name): Provider
    {
        return $user->providers()->create([
            'name' => $name,
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'provider-token',
            'description' => "{$name} description",
        ]);
    }

    private function server(
        User $user,
        Provider $provider,
        string $name,
        string $status,
        ?string $displayName = null,
    ): Server {
        return $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => $name,
            'display_name' => $displayName,
            'type' => ServerTypeEnum::app,
            'provisioning_status' => $status,
        ]);
    }
}
