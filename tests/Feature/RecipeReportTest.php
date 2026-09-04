<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipeReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_and_withdraw_one_private_report(): void
    {
        [$user, $author] = User::factory()->count(2)->create();
        $recipe = $this->publishedRecipe($author, 'Security baseline');

        $this->actingAs($user)
            ->from(route('gallery.show', $recipe))
            ->post(route('gallery.report.store', $recipe), [
                'reason' => 'security',
                'details' => '  Verify the downloaded signing key.  ',
            ])
            ->assertRedirect(route('gallery.show', $recipe))
            ->assertSessionHas('status', 'Your private gallery report was saved.');
        $this->assertDatabaseHas('recipe_reports', [
            'recipe_id' => $recipe->id,
            'user_id' => $user->id,
            'reason' => 'security',
        ]);
        $report = $user->recipeReports()->sole();
        $this->assertSame('Verify the downloaded signing key.', $report->details);
        $this->assertNotSame(
            $report->details,
            DB::table('recipe_reports')->where('id', $report->id)->value('details'),
        );

        $this->actingAs($user)->post(route('gallery.report.store', $recipe), [
            'reason' => 'outdated',
            'details' => '',
        ]);
        $this->assertSame(1, $user->recipeReports()->count());
        $this->assertSame('outdated', $user->recipeReports()->sole()->reason);
        $this->assertNull($user->recipeReports()->sole()->details);
        $this->assertSame([
            'Gallery recipe "Security baseline" was reported as security.',
            'Gallery recipe "Security baseline" report was updated to outdated.',
        ], $user->events()->oldest('id')->pluck('event')->all());
        $this->assertFalse($user->events()->where('event', 'like', '%signing key%')->exists());

        $this->actingAs($user)->get(route('gallery.show', $recipe))
            ->assertSuccessful()
            ->assertViewHas('currentReport', fn ($report): bool => $report->reason === 'outdated')
            ->assertSee('You reported this recipe as Outdated')
            ->assertSee('Update Report')
            ->assertSee('Withdraw Report');

        $this->actingAs($user)
            ->from(route('gallery.show', $recipe))
            ->delete(route('gallery.report.destroy', $recipe))
            ->assertRedirect(route('gallery.show', $recipe))
            ->assertSessionHas('status', 'Your gallery report was withdrawn.');
        $this->assertDatabaseCount('recipe_reports', 0);
        $this->assertSame('Gallery recipe "Security baseline" report was withdrawn.', $user->events()->latest('id')->value('event'));
    }

    public function test_report_actions_require_authentication_publication_non_authorship_and_ownership(): void
    {
        [$reporter, $intruder, $author] = User::factory()->count(3)->create();
        $published = $this->publishedRecipe($author, 'Public helper');
        $private = $author->recipes()->create([
            'name' => 'Private helper',
            'description' => 'Not shared.',
            'script' => 'echo private-report-secret',
        ]);

        $this->post(route('gallery.report.store', $published), ['reason' => 'broken'])
            ->assertRedirect(route('login'));
        $this->delete(route('gallery.report.destroy', $published))->assertRedirect(route('login'));
        $this->actingAs($author)->post(route('gallery.report.store', $published), ['reason' => 'broken'])
            ->assertForbidden();
        $this->actingAs($reporter)->post(route('gallery.report.store', $private), ['reason' => 'broken'])
            ->assertNotFound();
        $this->actingAs($reporter)->post(route('gallery.report.store', $published), ['reason' => 'invalid'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($reporter)->post(route('gallery.report.store', $published), [
            'reason' => 'other',
            'details' => str_repeat('x', 1001),
        ])->assertSessionHasErrors('details');
        $this->assertDatabaseCount('recipe_reports', 0);

        $reporter->recipeReports()->create([
            'recipe_id' => $published->id,
            'reason' => 'broken',
        ]);
        $this->actingAs($intruder)->delete(route('gallery.report.destroy', $published))->assertNotFound();
        $this->assertSame(1, $reporter->recipeReports()->count());

        $published->update(['is_published' => false, 'published_at' => null]);
        $this->actingAs($reporter)->delete(route('gallery.report.destroy', $published))->assertRedirect();
        $this->assertDatabaseCount('recipe_reports', 0);
    }

    public function test_reported_collection_is_owner_scoped_and_omits_report_content(): void
    {
        [$visitor, $other, $author] = User::factory()->count(3)->create();
        $reported = $this->publishedRecipe($author, 'Reported monitoring helper', 'monitoring', 9);
        $notReported = $this->publishedRecipe($author, 'Other runtime helper', 'runtime', 14);
        $visitor->recipeReports()->create([
            'recipe_id' => $reported->id,
            'reason' => 'broken',
            'details' => 'private-reporter-details',
        ]);
        $other->recipeReports()->create([
            'recipe_id' => $notReported->id,
            'reason' => 'security',
            'details' => 'foreign-reporter-details',
        ]);

        $this->actingAs($visitor)->get(route('gallery.index', ['scope' => 'reported']))
            ->assertSuccessful()
            ->assertViewHas('filters', fn (array $filters): bool => $filters['scope'] === 'reported')
            ->assertViewHas('metrics', [
                'published' => 1,
                'installs' => 9,
                'authors' => 1,
                'ratings' => 0,
            ])
            ->assertViewHas('recipes', function ($recipes) use ($reported): bool {
                $recipe = $recipes->sole();
                $report = $recipe->reports->sole();

                return $recipe->id === $reported->id
                    && ! array_key_exists('script', $recipe->getAttributes())
                    && ! array_key_exists('reason', $report->getAttributes())
                    && ! array_key_exists('details', $report->getAttributes());
            })
            ->assertSee('Reported by me')
            ->assertSee('Reported by you')
            ->assertDontSee($notReported->name)
            ->assertDontSee('reporter-details', false);
    }

    public function test_contributor_receives_anonymous_structured_feedback_only_for_their_recipe(): void
    {
        [$firstReporter, $secondReporter, $outsider, $author] = User::factory()->count(4)->create();
        $recipe = $this->publishedRecipe($author, 'Contributor helper');
        $firstReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'broken',
            'details' => 'Package name no longer resolves.',
        ]);
        $secondReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'broken',
            'details' => null,
        ]);

        $this->actingAs($author)->get(route('gallery.show', $recipe))
            ->assertSuccessful()
            ->assertViewHas('reportCounts', fn ($counts): bool => (int) $counts->get('broken') === 2)
            ->assertViewHas('recentReports', fn ($reports): bool => $reports->count() === 2
                && $reports->every(fn (RecipeReport $report): bool => ! array_key_exists('user_id', $report->getAttributes())))
            ->assertSee('Community reports')
            ->assertSee('Broken: 2')
            ->assertSee('Package name no longer resolves.')
            ->assertSee('No additional details were provided.')
            ->assertDontSee($firstReporter->name)
            ->assertDontSee($firstReporter->email)
            ->assertDontSee('Submit Report');

        $this->actingAs($outsider)->get(route('gallery.show', $recipe))
            ->assertSuccessful()
            ->assertViewHas('reportCounts', fn ($counts): bool => $counts->isEmpty())
            ->assertViewHas('recentReports', fn ($reports): bool => $reports->isEmpty())
            ->assertSee('Submit Report')
            ->assertDontSee('Package name no longer resolves.')
            ->assertDontSee('Broken: 2');
    }

    public function test_recipe_deletion_cascades_to_reports(): void
    {
        [$user, $author] = User::factory()->count(2)->create();
        $recipe = $this->publishedRecipe($author, 'Disposable report');
        $user->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'other',
        ]);

        $recipe->delete();

        $this->assertDatabaseCount('recipe_reports', 0);
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
            'script' => 'echo reported-gallery-script',
            'category' => $category,
            'is_published' => true,
            'published_at' => now(),
            'gallery_revision_at' => now(),
            'install_count' => $installs,
        ]);
    }
}
