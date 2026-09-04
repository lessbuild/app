<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_installed_user_can_create_update_and_remove_one_rating(): void
    {
        [$user, $author] = User::factory()->count(2)->create();
        $recipe = $this->publishedRecipe($author, 'Monitoring agent');
        $this->install($user, $recipe);

        $this->actingAs($user)
            ->from(route('gallery.show', $recipe))
            ->post(route('gallery.rating.store', $recipe), ['rating' => 5])
            ->assertRedirect(route('gallery.show', $recipe))
            ->assertSessionHas('status', 'Your gallery rating was saved.');
        $this->assertDatabaseHas('recipe_ratings', [
            'recipe_id' => $recipe->id,
            'user_id' => $user->id,
            'rating' => 5,
        ]);

        $this->actingAs($user)->post(route('gallery.rating.store', $recipe), ['rating' => 3]);
        $this->assertSame(1, $recipe->ratings()->count());
        $this->assertSame(3, $recipe->ratings()->sole()->rating);
        $this->actingAs($user)->get(route('gallery.show', $recipe))
            ->assertSuccessful()
            ->assertViewHas('canRate', true)
            ->assertViewHas('currentRating', fn ($rating): bool => $rating->rating === 3)
            ->assertSee('3.0 / 5 from 1 rating')
            ->assertSee('Update Rating')
            ->assertSee('Remove Rating');

        $this->actingAs($user)
            ->from(route('gallery.show', $recipe))
            ->delete(route('gallery.rating.destroy', $recipe))
            ->assertRedirect(route('gallery.show', $recipe))
            ->assertSessionHas('status', 'Your gallery rating was removed.');
        $this->assertDatabaseCount('recipe_ratings', 0);
    }

    public function test_rating_requires_a_published_installed_recipe_from_another_contributor(): void
    {
        [$installedUser, $outsider, $author] = User::factory()->count(3)->create();
        $recipe = $this->publishedRecipe($author, 'Security baseline');
        $this->install($installedUser, $recipe);

        $this->post(route('gallery.rating.store', $recipe), ['rating' => 5])
            ->assertRedirect(route('login'));
        $this->actingAs($author)->post(route('gallery.rating.store', $recipe), ['rating' => 5])
            ->assertForbidden();
        $this->actingAs($outsider)->post(route('gallery.rating.store', $recipe), ['rating' => 5])
            ->assertForbidden();
        $this->actingAs($installedUser)->post(route('gallery.rating.store', $recipe), ['rating' => 0])
            ->assertSessionHasErrors('rating');
        $this->actingAs($installedUser)->post(route('gallery.rating.store', $recipe), ['rating' => 6])
            ->assertSessionHasErrors('rating');
        $this->assertDatabaseCount('recipe_ratings', 0);

        $recipe->update(['is_published' => false, 'published_at' => null]);
        $this->actingAs($installedUser)->post(route('gallery.rating.store', $recipe), ['rating' => 4])
            ->assertNotFound();
        $this->assertDatabaseCount('recipe_ratings', 0);
    }

    public function test_gallery_can_sort_by_aggregate_rating_and_metrics_follow_filters(): void
    {
        [$visitor, $firstRater, $secondRater, $author] = User::factory()->count(4)->create();
        $highest = $this->publishedRecipe($author, 'Highest security', 'security');
        $middle = $this->publishedRecipe($author, 'Middle security', 'security');
        $unrated = $this->publishedRecipe($author, 'Unrated security', 'security');
        $runtime = $this->publishedRecipe($author, 'Rated runtime', 'runtime');
        $firstRater->recipeRatings()->create(['recipe_id' => $highest->id, 'rating' => 5]);
        $firstRater->recipeRatings()->create(['recipe_id' => $middle->id, 'rating' => 4]);
        $secondRater->recipeRatings()->create(['recipe_id' => $middle->id, 'rating' => 2]);
        $secondRater->recipeRatings()->create(['recipe_id' => $runtime->id, 'rating' => 5]);

        $this->actingAs($visitor)->get(route('gallery.index', [
            'category' => 'security',
            'sort' => 'top_rated',
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'published' => 3,
                'installs' => 0,
                'authors' => 1,
                'ratings' => 3,
            ])
            ->assertSeeInOrder([$highest->name, $middle->name, $unrated->name])
            ->assertSee('5.0 / 5 (1)')
            ->assertSee('3.0 / 5 (2)')
            ->assertSee('Not rated')
            ->assertDontSee($runtime->name);
    }

    public function test_user_cannot_remove_another_users_rating_and_recipe_deletion_cascades(): void
    {
        [$rater, $intruder, $author] = User::factory()->count(3)->create();
        $recipe = $this->publishedRecipe($author, 'Database helper');
        $rater->recipeRatings()->create(['recipe_id' => $recipe->id, 'rating' => 4]);

        $this->actingAs($intruder)->delete(route('gallery.rating.destroy', $recipe))
            ->assertNotFound();
        $this->assertSame(1, $recipe->ratings()->count());

        $recipe->update(['is_published' => false, 'published_at' => null]);
        $this->actingAs($rater)->delete(route('gallery.rating.destroy', $recipe))
            ->assertRedirect();
        $this->assertDatabaseCount('recipe_ratings', 0);

        $secondRecipe = $this->publishedRecipe($author, 'Disposable helper');
        $rater->recipeRatings()->create(['recipe_id' => $secondRecipe->id, 'rating' => 3]);
        $secondRecipe->delete();
        $this->assertDatabaseCount('recipe_ratings', 0);
    }

    private function publishedRecipe(User $author, string $name, string $category = 'monitoring'): Recipe
    {
        return $author->recipes()->create([
            'name' => $name,
            'description' => "Description for {$name}.",
            'script' => 'echo rated-gallery-script',
            'category' => $category,
            'is_published' => true,
            'published_at' => now(),
            'gallery_revision_at' => now(),
        ]);
    }

    private function install(User $user, Recipe $recipe): Recipe
    {
        return $user->recipes()->create([
            'name' => $recipe->name,
            'description' => $recipe->description,
            'script' => $recipe->script,
            'source_recipe_id' => $recipe->id,
            'source_revision_at' => $recipe->gallery_revision_at,
        ]);
    }
}
