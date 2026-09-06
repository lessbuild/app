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
            ->assertSee('Withdraw Report')
            ->assertSee('Withdraw your report for Security baseline? This cannot be undone.');

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
                    && $report->reason === 'broken'
                    && array_key_exists('resolved_at', $report->getAttributes())
                    && ! array_key_exists('details', $report->getAttributes())
                    && ! array_key_exists('resolution_note', $report->getAttributes());
            })
            ->assertSee('Reported by me')
            ->assertSee('Reported by you')
            ->assertDontSee($notReported->name)
            ->assertDontSee('reporter-details', false);
    }

    public function test_reporter_can_filter_open_and_resolved_collections_without_loading_encrypted_text(): void
    {
        [$visitor, $other, $author] = User::factory()->count(3)->create();
        $openRecipe = $this->publishedRecipe($author, 'Open feedback recipe', 'security', 3);
        $resolvedRecipe = $this->publishedRecipe($author, 'Resolved feedback recipe', 'monitoring', 7);
        $foreignRecipe = $this->publishedRecipe($author, 'Foreign feedback recipe', 'runtime', 11);
        $visitor->recipeReports()->create([
            'recipe_id' => $openRecipe->id,
            'reason' => 'broken',
            'details' => 'open-encrypted-details',
        ]);
        $visitor->recipeReports()->create([
            'recipe_id' => $resolvedRecipe->id,
            'reason' => 'outdated',
            'details' => 'resolved-encrypted-details',
            'resolved_at' => now(),
            'resolution_note' => 'encrypted-resolution-note',
        ]);
        $other->recipeReports()->create([
            'recipe_id' => $foreignRecipe->id,
            'reason' => 'security',
        ]);

        $this->actingAs($visitor)->get(route('gallery.index', ['scope' => 'reports_open']))
            ->assertSuccessful()
            ->assertViewHas('metrics', ['published' => 1, 'installs' => 3, 'authors' => 1, 'ratings' => 0])
            ->assertViewHas('recipes', function ($recipes) use ($openRecipe): bool {
                $recipe = $recipes->sole();
                $report = $recipe->reports->sole();

                return $recipe->id === $openRecipe->id
                    && $report->reason === 'broken'
                    && $report->resolved_at === null
                    && ! array_key_exists('details', $report->getAttributes())
                    && ! array_key_exists('resolution_note', $report->getAttributes());
            })
            ->assertSee('Reported by you: Broken')
            ->assertSee('Needs contributor review')
            ->assertDontSee($resolvedRecipe->name)
            ->assertDontSee('encrypted-details', false);

        $this->actingAs($visitor)->get(route('gallery.index', ['scope' => 'reports_resolved']))
            ->assertSuccessful()
            ->assertViewHas('metrics', ['published' => 1, 'installs' => 7, 'authors' => 1, 'ratings' => 0])
            ->assertViewHas('recipes', fn ($recipes): bool => $recipes->sole()->id === $resolvedRecipe->id
                && $recipes->sole()->reports->sole()->resolved_at !== null)
            ->assertSee('Reported by you: Outdated')
            ->assertSee('Resolved by contributor')
            ->assertDontSee($openRecipe->name)
            ->assertDontSee($foreignRecipe->name)
            ->assertDontSee('encrypted-resolution-note');
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

    public function test_contributor_can_resolve_a_report_without_learning_the_reporter_identity(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->publishedRecipe($author, 'Reviewed helper');
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'details' => 'Review this download.',
        ]);

        $this->actingAs($author)
            ->from(route('gallery.show', $recipe))
            ->patch(route('gallery.reports.resolve', [$recipe, $report]))
            ->assertRedirect(route('gallery.show', $recipe))
            ->assertSessionHas('status', 'The community report was marked as resolved.');

        $this->assertNotNull($report->refresh()->resolved_at);
        $this->assertSame(
            'A community report for gallery recipe "Reviewed helper" was resolved.',
            $author->events()->latest('id')->value('event'),
        );
        $this->assertStringNotContainsString($reporter->name, $author->events()->latest('id')->value('event'));

        $this->actingAs($author)->get(route('gallery.show', $recipe))
            ->assertSuccessful()
            ->assertViewHas('reportCounts', fn ($counts): bool => $counts->isEmpty())
            ->assertSee('Resolved')
            ->assertDontSee('Mark Resolved');

        $this->actingAs($reporter)->get(route('gallery.show', $recipe))
            ->assertSee('The contributor marked your Security report as resolved. Updating it will reopen it.');
    }

    public function test_report_update_reopens_resolved_feedback(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->publishedRecipe($author, 'Recurring helper');
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'broken',
            'resolved_at' => now(),
        ]);

        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), [
            'reason' => 'outdated',
            'details' => 'The issue returned.',
        ])->assertRedirect();

        $this->assertNull($report->refresh()->resolved_at);
        $this->actingAs($author)->get(route('dashboard'))
            ->assertSee('1 community report needs review')
            ->assertSee('Recurring helper');
    }

    public function test_only_recipe_contributor_can_resolve_matching_report(): void
    {
        [$reporter, $intruder, $author, $otherAuthor] = User::factory()->count(4)->create();
        $recipe = $this->publishedRecipe($author, 'Owned helper');
        $otherRecipe = $this->publishedRecipe($otherAuthor, 'Other helper');
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'broken',
        ]);

        $this->patch(route('gallery.reports.resolve', [$recipe, $report]))->assertRedirect(route('login'));
        $this->actingAs($intruder)->patch(route('gallery.reports.resolve', [$recipe, $report]))->assertNotFound();
        $this->actingAs($otherAuthor)->patch(route('gallery.reports.resolve', [$otherRecipe, $report]))->assertNotFound();
        $this->assertNull($report->refresh()->resolved_at);
    }

    public function test_contributor_can_leave_an_encrypted_resolution_note_for_the_reporter(): void
    {
        [$reporter, $outsider, $author] = User::factory()->count(3)->create();
        $recipe = $this->publishedRecipe($author, 'Resolution note helper');
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'broken',
            'details' => 'The package command fails.',
        ]);

        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]), [
            'resolution_note' => '  Updated the package source and pinned its signing key.  ',
        ])->assertRedirect();

        $report->refresh();
        $this->assertNotNull($report->resolved_at);
        $this->assertSame('Updated the package source and pinned its signing key.', $report->resolution_note);
        $this->assertNotSame(
            $report->resolution_note,
            DB::table('recipe_reports')->where('id', $report->id)->value('resolution_note'),
        );
        $this->assertArrayNotHasKey('resolution_note', $report->toArray());

        $this->actingAs($reporter)->get(route('gallery.show', $recipe))
            ->assertSee('Contributor resolution note')
            ->assertSee('Updated the package source and pinned its signing key.');
        $this->actingAs($author)->get(route('gallery.reports.index', ['status' => 'resolved']))
            ->assertSee('Resolution note')
            ->assertSee('Updated the package source and pinned its signing key.')
            ->assertSee('Update Resolution Note')
            ->assertSee('Leave empty to clear the note without reopening the report.')
            ->assertSee(route('gallery.reports.resolution-note.update', [$recipe, $report]));
        $this->actingAs($author)->get(route('gallery.show', $recipe))
            ->assertSee('Update Resolution Note')
            ->assertSee(route('gallery.reports.resolution-note.update', [$recipe, $report]));
        $this->actingAs($outsider)->get(route('gallery.show', $recipe))
            ->assertDontSee('Updated the package source and pinned its signing key.');
    }

    public function test_reporter_has_a_private_status_page_that_survives_unpublishing(): void
    {
        [$reporter, $outsider, $author] = User::factory()->count(3)->create();
        $recipe = $this->publishedRecipe($author, 'Durable report status helper');
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'details' => 'Private reporter evidence.',
            'resolved_at' => now(),
            'resolution_note' => 'Contributor remediation summary.',
        ]);

        $this->get(route('gallery.report.status', $report))->assertRedirect(route('login'));
        $this->actingAs($outsider)->get(route('gallery.report.status', $report))->assertNotFound();
        $this->actingAs($author)->get(route('gallery.report.status', $report))->assertNotFound();

        $this->actingAs($reporter)->get(route('gallery.report.status', $report))
            ->assertSuccessful()
            ->assertViewHas('report', function (RecipeReport $viewReport) use ($report): bool {
                return $viewReport->id === $report->id
                    && $viewReport->details === 'Private reporter evidence.'
                    && $viewReport->resolution_note === 'Contributor remediation summary.'
                    && ! array_key_exists('script', $viewReport->recipe->getAttributes())
                    && ! array_key_exists('description', $viewReport->recipe->getAttributes());
            })
            ->assertSee('My Report Status')
            ->assertSee('Durable report status helper')
            ->assertSee('Resolved by contributor')
            ->assertSee('Private reporter evidence.')
            ->assertSee('Contributor remediation summary.')
            ->assertSee(route('gallery.show', $recipe).'#gallery-report-heading')
            ->assertSee(route('gallery.report.destroy', $recipe))
            ->assertDontSee('reported-gallery-script');

        $recipe->update(['is_published' => false, 'published_at' => null]);

        $this->actingAs($reporter)->get(route('gallery.report.status', $report))
            ->assertSuccessful()
            ->assertSee('This recipe is no longer published.')
            ->assertSee('Private reporter evidence.')
            ->assertSee('Contributor remediation summary.')
            ->assertDontSee('View or update this report in the gallery')
            ->assertDontSee('reported-gallery-script');
    }

    public function test_contributor_can_update_and_clear_a_resolution_note_without_reopening_feedback(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->publishedRecipe($author, 'Editable resolution helper');
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'resolved_at' => now()->subHour(),
            'resolution_note' => 'Initial note.',
        ]);
        $resolvedAt = $report->refresh()->resolved_at;

        $this->actingAs($author)
            ->patch(route('gallery.reports.resolution-note.update', [$recipe, $report]), [
                'resolution_note' => '  Rotated the affected signing key.  ',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'The resolution note was updated.');

        $report->refresh();
        $this->assertTrue($report->resolved_at->equalTo($resolvedAt));
        $this->assertSame('Rotated the affected signing key.', $report->resolution_note);
        $this->assertNotSame(
            $report->resolution_note,
            DB::table('recipe_reports')->where('id', $report->id)->value('resolution_note'),
        );
        $this->assertSame(
            'A community report resolution note for gallery recipe "Editable resolution helper" was updated.',
            $author->events()->sole()->event,
        );
        $this->assertStringNotContainsString('signing key', $author->events()->sole()->event);
        $firstNotification = $reporter->unreadNotifications()->sole();
        $this->assertStringNotContainsString('signing key', json_encode($firstNotification->data, JSON_THROW_ON_ERROR));

        $updatedAt = $report->updated_at;
        $this->actingAs($author)
            ->patch(route('gallery.reports.resolution-note.update', [$recipe, $report]), [
                'resolution_note' => 'Rotated the affected signing key.',
            ])
            ->assertSessionHas('status', 'The resolution note is unchanged.');
        $this->assertTrue($report->refresh()->updated_at->equalTo($updatedAt));
        $this->assertSame(1, $author->events()->count());
        $this->assertSame(1, $reporter->notifications()->count());

        $this->actingAs($author)
            ->patch(route('gallery.reports.resolution-note.update', [$recipe, $report]), [
                'resolution_note' => '   ',
            ])
            ->assertSessionHas('status', 'The resolution note was cleared.');

        $report->refresh();
        $this->assertTrue($report->resolved_at->equalTo($resolvedAt));
        $this->assertNull($report->resolution_note);
        $this->assertSame(2, $author->events()->count());
        $this->assertSame(2, $reporter->notifications()->count());
        $this->assertSame(1, $reporter->unreadNotifications()->count());
        $this->assertSame(
            'The contributor resolved your report for "Editable resolution helper".',
            $reporter->unreadNotifications()->sole()->data['message'],
        );
    }

    public function test_resolution_note_updates_require_a_resolved_matching_owned_report_and_valid_note(): void
    {
        [$reporter, $intruder, $author, $otherAuthor] = User::factory()->count(4)->create();
        $recipe = $this->publishedRecipe($author, 'Protected resolution helper');
        $otherRecipe = $this->publishedRecipe($otherAuthor, 'Other resolution helper');
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'broken',
            'resolved_at' => now(),
            'resolution_note' => 'Original note.',
        ]);

        $this->patch(route('gallery.reports.resolution-note.update', [$recipe, $report]))
            ->assertRedirect(route('login'));
        $this->actingAs($intruder)
            ->patch(route('gallery.reports.resolution-note.update', [$recipe, $report]), ['resolution_note' => 'Intrusion'])
            ->assertNotFound();
        $this->actingAs($otherAuthor)
            ->patch(route('gallery.reports.resolution-note.update', [$otherRecipe, $report]), ['resolution_note' => 'Mismatch'])
            ->assertNotFound();
        $this->actingAs($author)
            ->patch(route('gallery.reports.resolution-note.update', [$recipe, $report]), [
                'resolution_note' => str_repeat('x', 1001),
            ])
            ->assertSessionHasErrors('resolution_note');
        $this->assertSame('Original note.', $report->refresh()->resolution_note);

        $report->update(['resolved_at' => null, 'resolution_note' => null]);
        $this->actingAs($author)
            ->patch(route('gallery.reports.resolution-note.update', [$recipe, $report]), ['resolution_note' => 'Not resolved'])
            ->assertStatus(409);
        $this->assertNull($report->refresh()->resolution_note);
    }

    public function test_resolution_note_is_bounded_and_cleared_when_feedback_reopens(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->publishedRecipe($author, 'Reopened note helper');
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'outdated',
        ]);

        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]), [
            'resolution_note' => str_repeat('x', 1001),
        ])->assertSessionHasErrors('resolution_note');
        $this->assertNull($report->refresh()->resolved_at);

        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]), [
            'resolution_note' => 'First resolution.',
        ]);
        $this->assertSame('First resolution.', $report->refresh()->resolution_note);

        $this->actingAs($author)->patch(route('gallery.reports.reopen', [$recipe, $report]));
        $this->assertNull($report->refresh()->resolved_at);
        $this->assertNull($report->resolution_note);

        $report->update(['resolved_at' => now(), 'resolution_note' => 'Second resolution.']);
        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), [
            'reason' => 'broken',
            'details' => 'The issue returned.',
        ]);
        $this->assertNull($report->refresh()->resolved_at);
        $this->assertNull($report->resolution_note);
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
