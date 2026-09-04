<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeInventoryInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_usage_and_assignment_counts_without_foreign_or_script_data(): void
    {
        $owner = User::factory()->create();
        $firstServer = $owner->servers()->create(['name' => 'Production']);
        $secondServer = $owner->servers()->create(['name' => 'Recovery']);
        $deploy = $this->recipe($owner, 'Deploy application');
        $monitor = $this->recipe($owner, 'Install monitoring');
        $this->recipe($owner, 'Future hardening');
        $firstServer->recipes()->attach($deploy, ['position' => 0]);
        $secondServer->recipes()->attach($deploy, ['position' => 0]);
        $firstServer->recipes()->attach($monitor, ['position' => 1]);

        $other = User::factory()->create();
        $foreignServer = $other->servers()->create(['name' => 'Foreign server']);
        $foreign = $this->recipe($other, 'Foreign private recipe');
        $foreignServer->recipes()->attach($foreign, ['position' => 0]);

        $this->actingAs($owner)->get(route('recipes.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 3
                && $metrics['in_use'] === 2
                && $metrics['unused'] === 1
                && $metrics['assignments'] === 3
                && $metrics['servers'] === 2
                && $metrics['latest_at'] !== null)
            ->assertSee('Matching recipes')
            ->assertSee('In use')
            ->assertSee('Unused')
            ->assertSee('Server assignments')
            ->assertSee('Covered servers')
            ->assertSee('Latest update')
            ->assertDontSee('Foreign private recipe')
            ->assertDontSee('recipe-script-secret');
    }

    public function test_metrics_apply_search_and_usage_filters_to_assignments(): void
    {
        $owner = User::factory()->create();
        $firstServer = $owner->servers()->create(['name' => 'Production']);
        $secondServer = $owner->servers()->create(['name' => 'Recovery']);
        $matching = $this->recipe($owner, 'Deploy application');
        $unused = $this->recipe($owner, 'Deploy draft');
        $other = $this->recipe($owner, 'Install monitoring');
        $firstServer->recipes()->attach($matching, ['position' => 0]);
        $secondServer->recipes()->attach($matching, ['position' => 0]);
        $firstServer->recipes()->attach($other, ['position' => 1]);

        $this->actingAs($owner)->get(route('recipes.index', [
            'search' => 'Deploy',
            'usage' => 'in_use',
        ]))
            ->assertSuccessful()
            ->assertViewHas('recipes', fn ($recipes): bool => $recipes->count() === 1
                && $recipes->sole()->id === $matching->id)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['in_use'] === 1
                && $metrics['unused'] === 0
                && $metrics['assignments'] === 2
                && $metrics['servers'] === 2
                && $metrics['latest_at'] !== null)
            ->assertDontSee(route('recipes.edit', $unused));
    }

    public function test_unused_and_empty_filters_have_explicit_metrics(): void
    {
        $owner = User::factory()->create();
        $unused = $this->recipe($owner, 'Ready for assignment');

        $this->actingAs($owner)->get(route('recipes.index', ['usage' => 'unused']))
            ->assertSuccessful()
            ->assertViewHas('recipes', fn ($recipes): bool => $recipes->sole()->id === $unused->id)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['in_use'] === 0
                && $metrics['unused'] === 1
                && $metrics['assignments'] === 0
                && $metrics['servers'] === 0
                && $metrics['latest_at'] !== null);

        $this->actingAs($owner)->get(route('recipes.index', ['search' => 'missing-recipe']))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'in_use' => 0,
                'unused' => 0,
                'assignments' => 0,
                'servers' => 0,
                'latest_at' => null,
            ])
            ->assertSee('No matching recipe')
            ->assertSee('No recipes match these filters');
    }

    private function recipe(User $user, string $name): Recipe
    {
        return $user->recipes()->create([
            'name' => $name,
            'description' => "{$name} description",
            'script' => 'recipe-script-secret',
        ]);
    }
}
