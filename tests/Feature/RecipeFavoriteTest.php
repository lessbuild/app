<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeFavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_an_idempotent_favorite_and_remove_it(): void
    {
        [$user, $author] = User::factory()->count(2)->create();
        $recipe = $this->publishedRecipe($author, 'Saved security helper');

        $this->actingAs($user)
            ->from(route('gallery.show', $recipe))
            ->post(route('gallery.favorite.store', $recipe))
            ->assertRedirect(route('gallery.show', $recipe))
            ->assertSessionHas('status', 'Recipe saved to your gallery favorites.');
        $this->assertDatabaseHas('recipe_favorites', [
            'recipe_id' => $recipe->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)->post(route('gallery.favorite.store', $recipe))
            ->assertSessionHas('status', 'This recipe is already in your gallery favorites.');
        $this->assertSame(1, $user->recipeFavorites()->count());
        $this->assertSame(1, $user->events()->where('event', 'like', '%was saved.')->count());
        $this->actingAs($user)->get(route('gallery.show', $recipe))
            ->assertSuccessful()
            ->assertViewHas('currentFavorite', fn ($favorite): bool => $favorite->recipe_id === $recipe->id)
            ->assertSee('Remove Saved')
            ->assertDontSee('Save Recipe');

        $this->actingAs($user)
            ->from(route('gallery.show', $recipe))
            ->delete(route('gallery.favorite.destroy', $recipe))
            ->assertRedirect(route('gallery.show', $recipe))
            ->assertSessionHas('status', 'Recipe removed from your gallery favorites.');
        $this->assertDatabaseCount('recipe_favorites', 0);
        $this->assertSame(1, $user->events()->where('event', 'like', '%removed from saved recipes.')->count());
    }

    public function test_favorite_actions_require_authentication_publication_and_ownership(): void
    {
        [$owner, $intruder, $author] = User::factory()->count(3)->create();
        $published = $this->publishedRecipe($author, 'Public helper');
        $private = $author->recipes()->create([
            'name' => 'Private helper',
            'description' => 'Not available in the gallery.',
            'script' => 'echo private-favorite-secret',
        ]);

        $this->post(route('gallery.favorite.store', $published))->assertRedirect(route('login'));
        $this->delete(route('gallery.favorite.destroy', $published))->assertRedirect(route('login'));
        $this->actingAs($owner)->post(route('gallery.favorite.store', $private))->assertNotFound();
        $owner->recipeFavorites()->create(['recipe_id' => $published->id]);

        $this->actingAs($intruder)->delete(route('gallery.favorite.destroy', $published))->assertNotFound();
        $this->assertSame(1, $owner->recipeFavorites()->count());

        $published->update(['is_published' => false, 'published_at' => null]);
        $this->actingAs($owner)->delete(route('gallery.favorite.destroy', $published))
            ->assertRedirect();
        $this->assertDatabaseCount('recipe_favorites', 0);
    }

    public function test_saved_collection_is_owner_scoped_and_gallery_index_omits_scripts(): void
    {
        [$visitor, $other, $author] = User::factory()->count(3)->create();
        $saved = $this->publishedRecipe($author, 'Saved monitoring helper', 'monitoring', 8);
        $notSaved = $this->publishedRecipe($author, 'Unsaved runtime helper', 'runtime', 12);
        $visitor->recipeFavorites()->create(['recipe_id' => $saved->id]);
        $other->recipeFavorites()->create(['recipe_id' => $notSaved->id]);

        $this->actingAs($visitor)->get(route('gallery.index', ['scope' => 'favorites']))
            ->assertSuccessful()
            ->assertViewHas('filters', fn (array $filters): bool => $filters['scope'] === 'favorites')
            ->assertViewHas('metrics', [
                'published' => 1,
                'installs' => 8,
                'authors' => 1,
                'ratings' => 0,
            ])
            ->assertViewHas('recipes', function ($recipes) use ($saved): bool {
                $recipe = $recipes->sole();

                return $recipe->id === $saved->id
                    && $recipe->favorites->count() === 1
                    && ! array_key_exists('script', $recipe->getAttributes());
            })
            ->assertSee('Saved by me')
            ->assertSee('Saved')
            ->assertSee('Remove saved')
            ->assertSee(route('gallery.favorite.destroy', $saved))
            ->assertDontSee($notSaved->name)
            ->assertDontSee('favorite-gallery-script', false);
    }

    public function test_recipe_deletion_cascades_to_favorites(): void
    {
        [$user, $author] = User::factory()->count(2)->create();
        $recipe = $this->publishedRecipe($author, 'Disposable favorite');
        $user->recipeFavorites()->create(['recipe_id' => $recipe->id]);

        $recipe->delete();

        $this->assertDatabaseCount('recipe_favorites', 0);
    }

    private function publishedRecipe(
        User $author,
        string $name,
        string $category = 'security',
        int $installs = 0,
    ): Recipe {
        return $author->recipes()->create([
            'name' => $name,
            'description' => "Description for {$name}.",
            'script' => 'echo favorite-gallery-script',
            'category' => $category,
            'is_published' => true,
            'published_at' => now(),
            'gallery_revision_at' => now(),
            'install_count' => $installs,
        ]);
    }
}
