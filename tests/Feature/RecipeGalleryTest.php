<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeGalleryTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_browse_search_filter_and_sort_published_recipes(): void
    {
        [$visitor, $firstAuthor, $secondAuthor] = User::factory()->count(3)->create();
        $popular = $this->publishedRecipe($firstAuthor, 'Popular firewall', 'security', 20, now()->subWeek());
        $recent = $this->publishedRecipe($secondAuthor, 'Recent firewall', 'security', 3, now());
        $this->publishedRecipe($secondAuthor, 'Node runtime', 'runtime', 50, now()->subDay());
        $firstAuthor->recipes()->create([
            'name' => 'Private firewall draft',
            'description' => 'This must stay private.',
            'script' => 'echo private-gallery-secret',
        ]);

        $this->actingAs($visitor)->get(route('gallery.index', [
            'search' => 'firewall',
            'category' => 'security',
            'sort' => 'popular',
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', ['published' => 2, 'installs' => 23, 'authors' => 2])
            ->assertSeeInOrder([$popular->name, $recent->name])
            ->assertSee($firstAuthor->name)
            ->assertSee($secondAuthor->name)
            ->assertDontSee('Node runtime')
            ->assertDontSee('Private firewall draft')
            ->assertDontSee('private-gallery-secret');
    }

    public function test_gallery_detail_exposes_only_an_explicitly_published_script(): void
    {
        [$visitor, $author] = User::factory()->count(2)->create();
        $published = $this->publishedRecipe($author, 'Auditable script', 'utilities');
        $private = $author->recipes()->create([
            'name' => 'Private script',
            'description' => 'Not shared.',
            'script' => 'echo private-secret',
        ]);

        $this->actingAs($visitor)->get(route('gallery.show', $published))
            ->assertSuccessful()
            ->assertSee('echo gallery-script')
            ->assertSee('Add to My Recipes')
            ->assertSee('runs as root');
        $this->actingAs($visitor)->get(route('gallery.show', $private))->assertNotFound();
        $this->actingAs($visitor)->post(route('gallery.install', $private))->assertNotFound();
    }

    public function test_install_creates_an_editable_private_snapshot_and_tracks_reuse(): void
    {
        [$visitor, $author] = User::factory()->count(2)->create();
        $source = $this->publishedRecipe($author, 'Install monitoring', 'monitoring', 4);

        $response = $this->actingAs($visitor)->post(route('gallery.install', $source));

        $copy = $visitor->recipes()->sole();
        $response->assertRedirect(route('recipes.edit', $copy));
        $this->assertSame($source->name, $copy->name);
        $this->assertSame($source->description, $copy->description);
        $this->assertSame($source->script, $copy->script);
        $this->assertSame($source->id, $copy->source_recipe_id);
        $this->assertFalse($copy->is_published);
        $this->assertNull($copy->published_at);
        $this->assertSame(5, $source->fresh()->install_count);

        $source->update(['script' => 'echo changed-upstream']);
        $this->assertSame('echo gallery-script', $copy->fresh()->script);
        $this->assertStringNotContainsString(
            'echo gallery-script',
            Recipe::query()->toBase()->find($copy->id)->script,
        );
    }

    public function test_owner_can_publish_and_unpublish_a_recipe_with_validation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('recipes.store'), [
            'name' => 'Missing category',
            'description' => 'Shared recipe.',
            'script' => 'echo shared',
            'is_published' => '1',
        ])->assertSessionHasErrors('category');

        $this->actingAs($user)->post(route('recipes.store'), [
            'name' => 'Published helper',
            'description' => 'Shared recipe.',
            'script' => 'echo shared',
            'is_published' => '1',
            'category' => 'utilities',
        ])->assertRedirect(route('recipes.index'));

        $recipe = $user->recipes()->sole();
        $this->assertTrue($recipe->is_published);
        $this->assertNotNull($recipe->published_at);
        $this->actingAs($user)->get(route('recipes.index'))
            ->assertSee('Published')
            ->assertSee(route('gallery.show', $recipe));

        $this->actingAs($user)->patch(route('recipes.update', $recipe), [
            'name' => $recipe->name,
            'description' => $recipe->description,
            'script' => $recipe->script,
            'is_published' => '0',
            'category' => 'utilities',
        ])->assertRedirect(route('recipes.index'));

        $this->assertFalse($recipe->fresh()->is_published);
        $this->assertNull($recipe->fresh()->published_at);
        $this->assertNull($recipe->fresh()->category);
        $this->actingAs($user)->get(route('gallery.show', $recipe))->assertNotFound();
    }

    public function test_gallery_requires_authentication(): void
    {
        $author = User::factory()->create();
        $recipe = $this->publishedRecipe($author, 'Shared helper', 'utilities');

        $this->get(route('gallery.index'))->assertRedirect(route('login'));
        $this->get(route('gallery.show', $recipe))->assertRedirect(route('login'));
        $this->post(route('gallery.install', $recipe))->assertRedirect(route('login'));
    }

    private function publishedRecipe(
        User $author,
        string $name,
        string $category,
        int $installs = 0,
        mixed $publishedAt = null,
    ): Recipe {
        return $author->recipes()->create([
            'name' => $name,
            'description' => "Description for {$name}.",
            'script' => 'echo gallery-script',
            'category' => $category,
            'is_published' => true,
            'published_at' => $publishedAt ?? now(),
            'install_count' => $installs,
        ]);
    }
}
