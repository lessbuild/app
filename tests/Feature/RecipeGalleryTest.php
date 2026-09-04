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
        $this->assertTrue($source->gallery_revision_at->equalTo($copy->source_revision_at));
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

    public function test_duplicate_install_is_prevented_and_new_revision_can_refresh_private_copy(): void
    {
        [$visitor, $author] = User::factory()->count(2)->create();
        $source = $this->publishedRecipe($author, 'Install monitoring', 'monitoring', 4);

        $this->actingAs($visitor)->post(route('gallery.install', $source));
        $copy = $visitor->recipes()->sole();
        $this->actingAs($visitor)->post(route('gallery.install', $source))
            ->assertRedirect(route('recipes.edit', $copy))
            ->assertSessionHas('status', 'This gallery recipe is already in your account.');
        $this->assertSame(1, $visitor->recipes()->count());
        $this->assertSame(5, $source->fresh()->install_count);

        $this->travel(1)->minute();
        $this->actingAs($author)->patch(route('recipes.update', $source), [
            'name' => 'Install monitoring agent',
            'description' => 'Updated monitoring setup.',
            'script' => 'echo gallery-script-v2',
            'is_published' => '1',
            'category' => 'monitoring',
        ])->assertRedirect(route('recipes.index'));
        $source->refresh();

        $this->assertTrue($source->gallery_revision_at->isAfter($copy->source_revision_at));
        $this->actingAs($visitor)->get(route('gallery.show', $source))
            ->assertSuccessful()
            ->assertSee('A newer gallery version is available')
            ->assertSee('Update My Copy')
            ->assertDontSee('Add to My Recipes');
        $this->actingAs($visitor)->get(route('recipes.edit', $copy))
            ->assertSuccessful()
            ->assertSee('Imported from Install monitoring agent')
            ->assertSee('Review Update')
            ->assertSee('Update Private Copy');
        $this->actingAs($visitor)->get(route('recipes.index'))
            ->assertSuccessful()
            ->assertSee('Gallery update available')
            ->assertSee(route('gallery.show', $source));

        $this->actingAs($visitor)->post(route('recipes.gallery.refresh', $copy))
            ->assertRedirect(route('recipes.edit', $copy))
            ->assertSessionHas('status', 'Your private copy was refreshed from the reviewed gallery version.');

        $copy->refresh();
        $this->assertSame('Install monitoring agent', $copy->name);
        $this->assertSame('Updated monitoring setup.', $copy->description);
        $this->assertSame('echo gallery-script-v2', $copy->script);
        $this->assertFalse($copy->is_published);
        $this->assertTrue($source->gallery_revision_at->equalTo($copy->source_revision_at));
        $this->actingAs($visitor)->get(route('gallery.show', $source))
            ->assertSee('Your private snapshot matches the current gallery revision.')
            ->assertDontSee('Update My Copy');
        $this->actingAs($visitor)->get(route('recipes.index'))
            ->assertSee('Gallery copy current')
            ->assertDontSee('Gallery update available');
    }

    public function test_published_copy_must_be_unpublished_before_refresh_and_other_users_are_forbidden(): void
    {
        [$owner, $intruder, $author] = User::factory()->count(3)->create();
        $source = $this->publishedRecipe($author, 'Security helper', 'security');
        $this->actingAs($owner)->post(route('gallery.install', $source));
        $copy = $owner->recipes()->sole();
        $copy->update([
            'is_published' => true,
            'category' => 'security',
            'published_at' => now(),
            'gallery_revision_at' => now(),
        ]);
        $source->update([
            'script' => 'echo gallery-script-v2',
            'gallery_revision_at' => now()->addMinute(),
        ]);

        $this->actingAs($owner)->post(route('recipes.gallery.refresh', $copy))
            ->assertRedirect(route('recipes.edit', $copy))
            ->assertSessionHas('status', 'Unpublish your copy before refreshing it from the gallery.');
        $this->assertSame('echo gallery-script', $copy->fresh()->script);

        $this->actingAs($intruder)->post(route('recipes.gallery.refresh', $copy))->assertForbidden();
        $this->assertSame('echo gallery-script', $copy->fresh()->script);
    }

    public function test_private_or_deleted_gallery_source_leaves_imported_snapshot_available(): void
    {
        [$visitor, $author] = User::factory()->count(2)->create();
        $source = $this->publishedRecipe($author, 'Temporary helper', 'utilities');
        $this->actingAs($visitor)->post(route('gallery.install', $source));
        $copy = $visitor->recipes()->sole();
        $source->update(['is_published' => false, 'published_at' => null, 'gallery_revision_at' => null]);

        $this->actingAs($visitor)->get(route('recipes.edit', $copy))
            ->assertSuccessful()
            ->assertSee('Gallery source unavailable')
            ->assertSee('Your encrypted private snapshot is unchanged');
        $this->actingAs($visitor)->post(route('recipes.gallery.refresh', $copy))->assertNotFound();
        $this->assertSame('echo gallery-script', $copy->fresh()->script);

        $source->delete();
        $this->assertNull($copy->fresh()->source_recipe_id);
        $this->assertSame('echo gallery-script', $copy->fresh()->script);
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
            'gallery_revision_at' => $publishedAt ?? now(),
            'install_count' => $installs,
        ]);
    }
}
