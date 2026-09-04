<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_management_records_lifecycle_without_script_contents(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('recipes.store'), [
            'name' => 'Audit helper',
            'description' => 'Private helper.',
            'script' => 'echo private-recipe-secret',
            'is_published' => '0',
        ]);
        $recipe = $user->recipes()->sole();

        $this->actingAs($user)->patch(route('recipes.update', $recipe), [
            'name' => 'Audit helper',
            'description' => 'Published helper.',
            'script' => 'echo private-recipe-secret',
            'is_published' => '1',
            'category' => 'utilities',
        ]);
        $this->actingAs($user)->patch(route('recipes.update', $recipe), [
            'name' => 'Audit helper revised',
            'description' => 'Published helper.',
            'script' => 'echo revised-private-secret',
            'is_published' => '1',
            'category' => 'utilities',
        ]);
        $this->actingAs($user)->patch(route('recipes.update', $recipe), [
            'name' => 'Audit helper revised',
            'description' => 'Private again.',
            'script' => 'echo revised-private-secret',
            'is_published' => '0',
        ]);
        $this->actingAs($user)->post(route('recipes.duplicate', $recipe));
        $copy = $user->recipes()->whereKeyNot($recipe->id)->sole();
        $this->actingAs($user)->delete(route('recipes.destroy', $copy));

        $this->assertSame([
            'Recipe "Audit helper" was created.',
            'Recipe "Audit helper" was published.',
            'Recipe "Audit helper revised" was updated.',
            'Recipe "Audit helper revised" was unpublished.',
            'Recipe "Audit helper revised" was duplicated as "Copy of Audit helper revised".',
            'Recipe "Copy of Audit helper revised" was deleted.',
        ], $user->events()->oldest('id')->pluck('event')->all());
        $this->assertSame(6, $user->events()->where('category', 'recipe')->count());
        $this->assertFalse(Event::query()->where('event', 'like', '%secret%')->exists());
        $this->assertSame(route('recipes.show', $recipe), $user->events()->oldest('id')->first()->url());
        $this->assertNull($user->events()->latest('id')->first()->url());
    }

    public function test_gallery_install_refresh_and_rating_actions_are_owner_scoped_activity(): void
    {
        [$user, $author] = User::factory()->count(2)->create();
        $source = $this->publishedRecipe($author);

        $this->actingAs($user)->post(route('gallery.install', $source));
        $copy = $user->recipes()->sole();
        $this->actingAs($user)->post(route('gallery.install', $source));
        $source->update([
            'script' => 'echo upstream-secret-v2',
            'gallery_revision_at' => now()->addMinute(),
        ]);
        $this->actingAs($user)->post(route('recipes.gallery.refresh', $copy));
        $this->actingAs($user)->post(route('gallery.rating.store', $source), ['rating' => 4]);
        $this->actingAs($user)->post(route('gallery.rating.store', $source), ['rating' => 5]);
        $this->actingAs($user)->delete(route('gallery.rating.destroy', $source));

        $this->assertSame([
            'Gallery recipe "Community helper" was installed as a private copy.',
            'Private gallery recipe "Community helper" was refreshed.',
            'Gallery recipe "Community helper" was rated 4/5.',
            'Gallery recipe "Community helper" rating was updated to 5/5.',
            'Gallery recipe "Community helper" rating was removed.',
        ], $user->events()->oldest('id')->pluck('event')->all());
        $this->assertSame(0, $author->events()->count());
        $this->assertFalse(Event::query()->where('event', 'like', '%upstream-secret%')->exists());

        $events = $user->events()->oldest('id')->get();
        $this->assertSame(route('recipes.show', $copy), $events[0]->url());
        $this->assertSame(route('gallery.show', $source), $events[2]->url());
        $this->actingAs($user)->get(route('activity.index', ['category' => 'recipe']))
            ->assertSuccessful()
            ->assertViewHas('events', fn ($events): bool => $events->total() === 5)
            ->assertSee('Community helper')
            ->assertDontSee('upstream-secret-v2');
        $export = $this->actingAs($user)->get(route('activity.export', ['category' => 'recipe']));
        $export->assertSuccessful();
        $this->assertStringContainsString('Gallery recipe ""Community helper"" was rated 4/5.', $export->streamedContent());
    }

    private function publishedRecipe(User $author): Recipe
    {
        return $author->recipes()->create([
            'name' => 'Community helper',
            'description' => 'Shared helper.',
            'script' => 'echo upstream-secret-v1',
            'category' => 'utilities',
            'is_published' => true,
            'published_at' => now(),
            'gallery_revision_at' => now(),
        ]);
    }
}
