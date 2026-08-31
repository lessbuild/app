<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeInventoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_combine_search_and_usage_filters(): void
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create(['name' => 'Production']);
        $matching = $this->recipe($owner, 'Security Baseline', 'Protect production hosts.');
        $server->recipes()->attach($matching, ['position' => 0]);
        $this->recipe($owner, 'Security Draft', 'Not assigned yet.');
        $this->recipe($owner, 'Monitoring Agent', 'Assigned but unrelated.');
        $monitoring = $owner->recipes()->where('name', 'Monitoring Agent')->sole();
        $server->recipes()->attach($monitoring, ['position' => 1]);

        $other = User::factory()->create();
        $foreignServer = $other->servers()->create(['name' => 'Private Production']);
        $foreign = $this->recipe($other, 'Private Security Baseline', 'Foreign recipe.');
        $foreignServer->recipes()->attach($foreign, ['position' => 0]);

        $this->actingAs($owner)->get(route('recipes.index', [
            'search' => 'Security',
            'usage' => 'in_use',
        ]))
            ->assertSuccessful()
            ->assertSee(route('recipes.edit', $matching))
            ->assertSee('1 server')
            ->assertSee('value="Security"', false)
            ->assertSee('value="in_use" selected', false)
            ->assertDontSee('Security Draft')
            ->assertDontSee('Monitoring Agent')
            ->assertDontSee('Private Security Baseline');
    }

    public function test_encrypted_script_body_is_not_a_searchable_or_rendered_field(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner, 'Bootstrap', 'Install required packages.', 'echo hidden-token-value');

        $this->actingAs($owner)->get(route('recipes.index'))
            ->assertSuccessful()
            ->assertSee(route('recipes.edit', $recipe))
            ->assertDontSee('hidden-token-value');

        $this->actingAs($owner)->get(route('recipes.index', ['search' => 'hidden-token-value']))
            ->assertSuccessful()
            ->assertSee('No recipes match these filters')
            ->assertDontSee(route('recipes.edit', $recipe));
    }

    public function test_invalid_filters_are_ignored_and_empty_results_can_be_reset(): void
    {
        $owner = User::factory()->create();
        $this->recipe($owner, 'Visible Recipe', 'Visible description.');

        $this->actingAs($owner)->get(route('recipes.index', [
            'search' => '   ',
            'usage' => 'sometimes',
        ]))
            ->assertSuccessful()
            ->assertSee('Visible Recipe')
            ->assertDontSee('Clear filters');

        $this->actingAs($owner)->get(route('recipes.index', ['search' => 'missing']))
            ->assertSuccessful()
            ->assertSee('No recipes match these filters')
            ->assertSee('Try changing or clearing the selected filters.')
            ->assertSee('Clear filters');
    }

    public function test_filter_state_is_preserved_in_pagination_links(): void
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create(['name' => 'Production']);
        foreach (range(1, 16) as $index) {
            $recipe = $this->recipe($owner, "Fleet Recipe {$index}", 'Fleet automation.');
            $server->recipes()->attach($recipe, ['position' => $index]);
        }

        $this->actingAs($owner)->get(route('recipes.index', [
            'search' => 'Fleet',
            'usage' => 'in_use',
        ]))
            ->assertSuccessful()
            ->assertSee('page=2', false)
            ->assertSee('search=Fleet', false)
            ->assertSee('usage=in_use', false);
    }

    private function recipe(
        User $user,
        string $name,
        string $description,
        string $script = 'echo provision',
    ): Recipe {
        return $user->recipes()->create([
            'name' => $name,
            'description' => $description,
            'script' => $script,
        ]);
    }
}
