<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeDuplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_duplicate_an_encrypted_recipe_into_an_unassigned_copy(): void
    {
        $owner = User::factory()->create();
        $source = $owner->recipes()->create([
            'name' => 'Install monitoring',
            'description' => 'Install the private monitoring agent.',
            'script' => 'MONITORING_TOKEN=secret-value install-agent',
        ]);
        $server = $owner->servers()->create(['name' => 'Production']);
        $server->recipes()->attach($source, ['position' => 0]);

        $response = $this->actingAs($owner)->post(route('recipes.duplicate', $source));

        $copy = Recipe::query()->whereKeyNot($source->id)->sole();
        $response
            ->assertRedirect(route('recipes.edit', $copy))
            ->assertSessionHas('status', 'Recipe duplicated. Review and rename the copy before using it.');
        $this->assertSame('Copy of Install monitoring', $copy->name);
        $this->assertSame($source->description, $copy->description);
        $this->assertSame($source->script, $copy->script);
        $this->assertSame('Install monitoring', $source->fresh()->name);
        $this->assertSame([$source->id], $server->recipes()->pluck('recipes.id')->all());
        $this->assertSame(0, $copy->servers()->count());

        $rawScript = Recipe::query()->toBase()->find($copy->id)->script;
        $this->assertNotSame($copy->script, $rawScript);
        $this->assertStringNotContainsString('secret-value', $rawScript);
        $this->assertArrayNotHasKey('script', $copy->toArray());

        $this->actingAs($owner)->get(route('recipes.edit', $copy))
            ->assertSuccessful()
            ->assertSee('Recipe duplicated. Review and rename the copy before using it.')
            ->assertSee('MONITORING_TOKEN=secret-value install-agent');

        $this->actingAs($owner)->get(route('recipes.index'))
            ->assertSuccessful()
            ->assertSee(route('recipes.duplicate', $source))
            ->assertSee(route('recipes.duplicate', $copy));
    }

    public function test_duplicate_name_is_limited_to_the_recipe_validation_length(): void
    {
        $owner = User::factory()->create();
        $source = $owner->recipes()->create([
            'name' => str_repeat('a', 255),
            'description' => null,
            'script' => 'echo provision',
        ]);

        $this->actingAs($owner)->post(route('recipes.duplicate', $source))->assertRedirect();

        $copy = Recipe::query()->whereKeyNot($source->id)->sole();
        $this->assertSame(255, mb_strlen($copy->name));
        $this->assertStringStartsWith('Copy of ', $copy->name);
    }

    public function test_another_user_cannot_duplicate_the_recipe(): void
    {
        [$owner, $intruder] = User::factory()->count(2)->create();
        $source = $owner->recipes()->create([
            'name' => 'Private Recipe',
            'description' => null,
            'script' => 'echo private',
        ]);

        $this->actingAs($intruder)
            ->post(route('recipes.duplicate', $source))
            ->assertForbidden();

        $this->assertDatabaseCount('recipes', 1);
    }

    public function test_duplication_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $source = $owner->recipes()->create([
            'name' => 'Private Recipe',
            'description' => null,
            'script' => 'echo private',
        ]);

        $this->post(route('recipes.duplicate', $source))->assertRedirect(route('login'));
        $this->assertDatabaseCount('recipes', 1);
    }
}
