<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipeFeedbackInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_is_owner_scoped_anonymous_and_defaults_to_unresolved_feedback(): void
    {
        [$reporter, $otherReporter, $author, $otherAuthor] = User::factory()->count(4)->create();
        $owned = $this->recipe($author, 'Owned reported recipe');
        $foreign = $this->recipe($otherAuthor, 'Foreign reported recipe');
        $open = $reporter->recipeReports()->create([
            'recipe_id' => $owned->id,
            'reason' => 'security',
            'details' => 'Anonymous owned details.',
        ]);
        $otherReporter->recipeReports()->create([
            'recipe_id' => $owned->id,
            'reason' => 'broken',
            'details' => 'Already reviewed details.',
            'resolved_at' => now(),
        ]);
        $reporter->recipeReports()->create([
            'recipe_id' => $foreign->id,
            'reason' => 'outdated',
            'details' => 'Foreign private details.',
        ]);

        $this->actingAs($author)->get(route('gallery.reports.index'))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'search' => null,
                'status' => 'unresolved',
                'reason' => null,
                'date_from' => null,
                'date_to' => null,
                'age' => null,
                'sort' => 'newest',
                'recipe' => null,
                'report' => null,
            ])
            ->assertViewHas('metrics', [
                'matching' => 1,
                'unresolved' => 1,
                'resolved' => 0,
                'recipes' => 1,
            ])
            ->assertViewHas('reports', function ($reports) use ($open): bool {
                $report = $reports->sole();

                return $report->id === $open->id
                    && ! array_key_exists('user_id', $report->getAttributes());
            })
            ->assertSee('Anonymous owned details.')
            ->assertSee('Owned reported recipe')
            ->assertSee('Resolve Selected')
            ->assertSee(route('gallery.reports.resolve-many'))
            ->assertSee('value="'.$open->id.'"', false)
            ->assertDontSee('Already reviewed details.')
            ->assertDontSee('Foreign private details.')
            ->assertDontSee($reporter->email)
            ->assertDontSee($otherReporter->email);
    }

    public function test_inbox_combines_status_and_reason_filters_with_pagination(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Frequently reviewed recipe');

        foreach (range(1, 22) as $number) {
            $reporter = $number === 1 ? $reporter : User::factory()->create();
            $reporter->recipeReports()->create([
                'recipe_id' => $recipe->id,
                'reason' => $number <= 21 ? 'broken' : 'security',
                'details' => "Resolved report {$number}",
                'resolved_at' => now(),
            ]);
        }

        $response = $this->actingAs($author)->get(route('gallery.reports.index', [
            'recipe' => $recipe->id,
            'search' => 'Frequently reviewed',
            'status' => 'resolved',
            'reason' => 'broken',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
            'sort' => 'oldest',
        ]));

        $response->assertSuccessful()
            ->assertViewHas('metrics', [
                'matching' => 21,
                'unresolved' => 0,
                'resolved' => 21,
                'recipes' => 1,
            ])
            ->assertSee('Resolved report 1')
            ->assertSee('Reopen Selected')
            ->assertSee(route('gallery.reports.reopen-many'))
            ->assertDontSee('Resolved report 21')
            ->assertDontSee('Resolved report 22')
            ->assertDontSee('Resolve Selected');

        $reports = $response->viewData('reports');
        $this->assertCount(20, $reports);
        parse_str((string) parse_url($reports->nextPageUrl(), PHP_URL_QUERY), $nextPageQuery);
        $this->assertSame('Frequently reviewed', $nextPageQuery['search']);
        $this->assertSame((string) $recipe->id, $nextPageQuery['recipe']);
        $this->assertSame('resolved', $nextPageQuery['status']);
        $this->assertSame('broken', $nextPageQuery['reason']);
        $this->assertSame(now()->toDateString(), $nextPageQuery['date_from']);
        $this->assertSame(now()->toDateString(), $nextPageQuery['date_to']);
        $this->assertSame('oldest', $nextPageQuery['sort']);
    }

    public function test_invalid_filters_are_normalized_and_empty_feedback_is_explicit(): void
    {
        $author = User::factory()->create();

        $this->actingAs($author)->get(route('gallery.reports.index', [
            'search' => '   ',
            'status' => 'deleted',
            'reason' => 'invalid',
            'date_from' => '2026-02-30',
            'date_to' => 'not-a-date',
            'age' => 'ancient',
            'sort' => 'random',
            'recipe' => '0',
            'report' => '-3',
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'search' => null,
                'status' => 'unresolved',
                'reason' => null,
                'date_from' => null,
                'date_to' => null,
                'age' => null,
                'sort' => 'newest',
                'recipe' => null,
                'report' => null,
            ])
            ->assertSee('No community reports match these filters')
            ->assertSee(route('gallery.reports.export', ['status' => 'unresolved', 'sort' => 'newest']));
    }

    public function test_contributor_and_reporter_searches_treat_sql_wildcards_as_literal_text(): void
    {
        [$reporter, $otherReporter, $author] = User::factory()->count(3)->create();
        $matchingRecipe = $this->recipe($author, 'Recipe 100% reviewed');
        $ordinaryRecipe = $this->recipe($author, 'Ordinary recipe');
        $matching = $reporter->recipeReports()->create(['recipe_id' => $matchingRecipe->id, 'reason' => 'broken']);
        $otherReporter->recipeReports()->create(['recipe_id' => $ordinaryRecipe->id, 'reason' => 'broken']);

        $this->actingAs($author)->get(route('gallery.reports.index', ['search' => '%']))
            ->assertSuccessful()
            ->assertViewHas('reports', fn ($reports): bool => $reports->count() === 1
                && $reports->sole()->id === $matching->id)
            ->assertDontSee('Ordinary recipe');

        $this->actingAs($reporter)->get(route('gallery.reports.mine', ['search' => '%']))
            ->assertSuccessful()
            ->assertViewHas('reports', fn ($reports): bool => $reports->count() === 1
                && $reports->sole()->id === $matching->id);
    }

    public function test_focused_report_view_is_anchored_owner_scoped_and_status_independent(): void
    {
        [$firstReporter, $secondReporter, $foreignReporter, $author, $otherAuthor] = User::factory()->count(5)->create();
        $recipe = $this->recipe($author, 'Focused feedback recipe');
        $foreignRecipe = $this->recipe($otherAuthor, 'Foreign focused recipe');
        $target = $firstReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'details' => 'Focused resolved details',
            'resolved_at' => now(),
        ]);
        $secondReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'broken',
            'details' => 'Other owned details',
        ]);
        $foreign = $foreignReporter->recipeReports()->create([
            'recipe_id' => $foreignRecipe->id,
            'reason' => 'other',
            'details' => 'Foreign private details',
        ]);

        $this->actingAs($author)->get(route('gallery.reports.index', [
            'status' => 'all',
            'report' => $target->id,
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', fn (array $filters): bool => $filters['report'] === $target->id
                && $filters['status'] === 'all')
            ->assertViewHas('reports', fn ($reports): bool => $reports->sole()->id === $target->id)
            ->assertSee('Showing the community report opened from your notification.')
            ->assertSee('id="report-'.$target->id.'"', false)
            ->assertSee('Focused resolved details')
            ->assertDontSee('Other owned details')
            ->assertDontSee('Foreign private details');

        $this->actingAs($author)->get(route('gallery.reports.index', [
            'status' => 'all',
            'report' => $foreign->id,
        ]))
            ->assertSuccessful()
            ->assertViewHas('reports', fn ($reports): bool => $reports->isEmpty())
            ->assertDontSee('Foreign private details');
    }

    public function test_recipe_focus_composes_with_status_and_export_without_crossing_ownership(): void
    {
        [$firstReporter, $secondReporter, $otherReporter, $foreignReporter, $author, $otherAuthor] = User::factory()->count(6)->create();
        $recipe = $this->recipe($author, 'Complete focused recipe');
        $otherRecipe = $this->recipe($author, 'Other owned recipe');
        $foreignRecipe = $this->recipe($otherAuthor, 'Foreign recipe focus');
        $open = $firstReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'broken',
            'details' => 'Focused open feedback',
        ]);
        $resolved = $secondReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'details' => 'Focused resolved feedback',
            'resolved_at' => now(),
        ]);
        $otherReporter->recipeReports()->create([
            'recipe_id' => $otherRecipe->id,
            'reason' => 'other',
            'details' => 'Other owned feedback',
        ]);
        $foreignReporter->recipeReports()->create([
            'recipe_id' => $foreignRecipe->id,
            'reason' => 'outdated',
            'details' => 'Foreign recipe feedback',
        ]);

        $response = $this->actingAs($author)->get(route('gallery.reports.index', [
            'status' => 'all',
            'recipe' => $recipe->id,
        ]));
        $response->assertSuccessful()
            ->assertViewHas('filters', fn (array $filters): bool => $filters['recipe'] === $recipe->id)
            ->assertViewHas('reports', fn ($reports): bool => $reports->pluck('id')->sort()->values()->all() === collect([$open->id, $resolved->id])->sort()->values()->all())
            ->assertSee('Showing all matching feedback for the selected recipe.')
            ->assertSee('Focused open feedback')
            ->assertSee('Focused resolved feedback')
            ->assertDontSee('Other owned feedback')
            ->assertDontSee('Foreign recipe feedback');

        $rows = $this->csvRows($this->actingAs($author)->get(route('gallery.reports.export', [
            'status' => 'all',
            'recipe' => $recipe->id,
        ]))->streamedContent());
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing([(string) $open->id, (string) $resolved->id], [$rows[1][0], $rows[2][0]]);

        $this->actingAs($author)->get(route('gallery.reports.index', [
            'status' => 'all',
            'recipe' => $foreignRecipe->id,
        ]))
            ->assertSuccessful()
            ->assertViewHas('reports', fn ($reports): bool => $reports->isEmpty())
            ->assertDontSee('Foreign recipe feedback');
    }

    public function test_minimum_age_filter_uses_exact_cutoffs_and_persists_through_pagination_and_export(): void
    {
        $this->travelTo('2026-09-04 12:00:00');
        $author = User::factory()->create();
        $recipe = $this->recipe($author, 'Aged feedback recipe');
        $reporters = User::factory()->count(22)->create();
        $cutoff = now()->subDays(7);

        foreach (range(1, 21) as $number) {
            $report = $reporters[$number - 1]->recipeReports()->create([
                'recipe_id' => $recipe->id,
                'reason' => 'security',
                'details' => "Stale report {$number}",
            ]);
            $report->forceFill([
                'created_at' => $cutoff->copy()->subMinutes($number - 1),
                'updated_at' => $cutoff->copy()->subMinutes($number - 1),
            ])->saveQuietly();
        }
        $recent = $reporters[21]->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'details' => 'Too recent by one second',
        ]);
        $recent->forceFill([
            'created_at' => $cutoff->copy()->addSecond(),
            'updated_at' => $cutoff->copy()->addSecond(),
        ])->saveQuietly();

        $response = $this->actingAs($author)->get(route('gallery.reports.index', [
            'reason' => 'security',
            'age' => '7d',
            'sort' => 'oldest',
        ]));

        $response->assertSuccessful()
            ->assertViewHas('filters', fn (array $filters): bool => $filters['age'] === '7d')
            ->assertViewHas('metrics', [
                'matching' => 21,
                'unresolved' => 21,
                'resolved' => 0,
                'recipes' => 1,
            ])
            ->assertSee('Stale report 21')
            ->assertDontSee('Too recent by one second');
        $reports = $response->viewData('reports');
        $this->assertCount(20, $reports);
        parse_str((string) parse_url($reports->nextPageUrl(), PHP_URL_QUERY), $nextPageQuery);
        $this->assertSame('7d', $nextPageQuery['age']);
        $this->assertSame('security', $nextPageQuery['reason']);
        $this->assertSame('oldest', $nextPageQuery['sort']);

        $rows = $this->csvRows($this->actingAs($author)->get(route('gallery.reports.export', [
            'reason' => 'security',
            'age' => '7d',
            'sort' => 'oldest',
        ]))->streamedContent());
        $this->assertCount(22, $rows);
        $this->assertSame('Stale report 21', $rows[1][6]);
        $this->assertSame('Stale report 1', $rows[21][6]);
        $this->assertStringNotContainsString('Too recent by one second', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function test_inbox_supports_newest_oldest_and_recently_updated_ordering(): void
    {
        [$firstReporter, $secondReporter, $thirdReporter, $author] = User::factory()->count(4)->create();
        $recipe = $this->recipe($author, 'Sorted feedback recipe');
        $first = $firstReporter->recipeReports()->create(['recipe_id' => $recipe->id, 'reason' => 'other', 'details' => 'First report']);
        $second = $secondReporter->recipeReports()->create(['recipe_id' => $recipe->id, 'reason' => 'security', 'details' => 'Second report']);
        $third = $thirdReporter->recipeReports()->create(['recipe_id' => $recipe->id, 'reason' => 'broken', 'details' => 'Third report']);
        $first->forceFill(['created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-03 10:00:00'])->saveQuietly();
        $second->forceFill(['created_at' => '2026-08-02 10:00:00', 'updated_at' => '2026-08-01 10:00:00'])->saveQuietly();
        $third->forceFill(['created_at' => '2026-08-03 10:00:00', 'updated_at' => '2026-08-02 10:00:00'])->saveQuietly();

        $this->actingAs($author)->get(route('gallery.reports.index', ['sort' => 'newest']))
            ->assertSeeInOrder(['Third report', 'Second report', 'First report']);
        $this->actingAs($author)->get(route('gallery.reports.index', ['sort' => 'oldest']))
            ->assertSeeInOrder(['First report', 'Second report', 'Third report']);
        $this->actingAs($author)->get(route('gallery.reports.index', ['sort' => 'updated']))
            ->assertSeeInOrder(['First report', 'Third report', 'Second report']);

        $priorityResponse = $this->actingAs($author)->get(route('gallery.reports.index', ['sort' => 'priority']));
        $priorityResponse
            ->assertSuccessful()
            ->assertViewHas('filters', fn (array $filters): bool => $filters['sort'] === 'priority')
            ->assertSeeInOrder(['Second report', 'Third report', 'First report'])
            ->assertSee('bg-red-100 text-red-700', false)
            ->assertSee('bg-orange-100 text-orange-700', false)
            ->assertSee('bg-blue-100 text-blue-700', false)
            ->assertSee(route('gallery.reports.export', ['status' => 'unresolved', 'sort' => 'priority']));

        $rows = $this->csvRows($this->actingAs($author)->get(route('gallery.reports.export', [
            'sort' => 'priority',
        ]))->streamedContent());
        $this->assertSame([(string) $second->id, (string) $third->id, (string) $first->id], [
            $rows[1][0],
            $rows[2][0],
            $rows[3][0],
        ]);
    }

    public function test_priority_sort_keeps_open_reports_ahead_of_resolved_reports(): void
    {
        [$openReporter, $resolvedReporter, $author] = User::factory()->count(3)->create();
        $recipe = $this->recipe($author, 'State-priority recipe');
        $openReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'other',
            'details' => 'Open lower-priority report',
        ]);
        $resolvedReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'details' => 'Resolved security report',
            'resolved_at' => now(),
        ]);

        $this->actingAs($author)->get(route('gallery.reports.index', [
            'status' => 'all',
            'sort' => 'priority',
        ]))
            ->assertSuccessful()
            ->assertSeeInOrder(['Open lower-priority report', 'Resolved security report']);
    }

    public function test_mixed_inbox_has_independent_accessible_batch_selection_controls(): void
    {
        [$openReporter, $resolvedReporter, $author] = User::factory()->count(3)->create();
        $recipe = $this->recipe($author, 'Selectable feedback recipe');
        $open = $openReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'broken',
        ]);
        $resolved = $resolvedReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'resolved_at' => now(),
        ]);

        $response = $this->actingAs($author)->get(route('gallery.reports.index', ['status' => 'all']));

        $response->assertSuccessful()
            ->assertSee('Select All Open')
            ->assertSee('Clear Open Selection')
            ->assertSee('Select All Resolved')
            ->assertSee('Clear Resolved Selection')
            ->assertSee('x-bind:disabled="openSelected.length === 0"', false)
            ->assertSee('x-bind:disabled="resolvedSelected.length === 0"', false)
            ->assertSee('x-model.number="openSelected"', false)
            ->assertSee('x-model.number="resolvedSelected"', false)
            ->assertSee('value="'.$open->id.'"', false)
            ->assertSee('value="'.$resolved->id.'"', false);
    }

    public function test_batch_validation_feedback_is_attached_only_to_the_submitted_action(): void
    {
        [$openReporter, $resolvedReporter, $author] = User::factory()->count(3)->create();
        $recipe = $this->recipe($author, 'Validation feedback recipe');
        $openReporter->recipeReports()->create(['recipe_id' => $recipe->id, 'reason' => 'broken']);
        $resolvedReporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'resolved_at' => now(),
        ]);
        $inbox = route('gallery.reports.index', ['status' => 'all']);

        $response = $this->actingAs($author)
            ->from($inbox)
            ->followingRedirects()
            ->patch(route('gallery.reports.resolve-many'), ['reports' => []]);

        $response->assertSuccessful()->assertSee('The reports field is required.');
        $this->assertSame(1, substr_count($response->getContent(), 'The reports field is required.'));
    }

    public function test_filtered_export_is_owner_scoped_anonymous_and_spreadsheet_safe(): void
    {
        [$reporter, $otherReporter, $author, $otherAuthor] = User::factory()->count(4)->create();
        $owned = $this->recipe($author, '=HYPERLINK("https://example.test")');
        $foreign = $this->recipe($otherAuthor, 'Foreign export recipe');
        $matching = $reporter->recipeReports()->create([
            'recipe_id' => $owned->id,
            'reason' => 'security',
            'details' => "+SUM(1,1)\0 private finding",
            'resolved_at' => '2026-09-04 12:00:00',
            'resolution_note' => '=Addressed after package upgrade',
        ]);
        $matching->forceFill(['created_at' => '2026-08-20 12:00:00'])->saveQuietly();
        $earlier = User::factory()->create()->recipeReports()->create([
            'recipe_id' => $owned->id,
            'reason' => 'security',
            'details' => 'Earlier matching feedback.',
            'resolved_at' => '2026-09-04 11:00:00',
        ]);
        $earlier->forceFill(['created_at' => '2026-08-20 10:00:00'])->saveQuietly();
        $otherReporter->recipeReports()->create([
            'recipe_id' => $owned->id,
            'reason' => 'broken',
            'details' => 'Wrong reason feedback.',
            'resolved_at' => now(),
        ]);
        $reporter->recipeReports()->create([
            'recipe_id' => $foreign->id,
            'reason' => 'security',
            'details' => 'Foreign feedback.',
            'resolved_at' => now(),
        ]);
        $outsideDate = User::factory()->create()->recipeReports()->create([
            'recipe_id' => $owned->id,
            'reason' => 'security',
            'details' => 'Outside date feedback.',
            'resolved_at' => now(),
        ]);
        $outsideDate->forceFill(['created_at' => '2026-08-19 23:59:59'])->saveQuietly();

        $response = $this->actingAs($author)->get(route('gallery.reports.export', [
            'search' => 'HYPERLINK',
            'status' => 'resolved',
            'reason' => 'security',
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
            'sort' => 'oldest',
        ]));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment; filename=lessbuild-community-feedback-', (string) $response->headers->get('content-disposition'));

        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame([
            'Report ID',
            'Recipe ID',
            'Recipe',
            'Category',
            'Issue type',
            'Review status',
            'Details',
            'Reported at',
            'Resolved at',
            'Resolution note',
        ], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame((string) $earlier->id, $rows[1][0]);
        $this->assertSame('Earlier matching feedback.', $rows[1][6]);
        $this->assertSame((string) $matching->id, $rows[2][0]);
        $this->assertSame("'=HYPERLINK(\"https://example.test\")", $rows[2][2]);
        $this->assertSame('resolved', $rows[2][5]);
        $this->assertSame("'+SUM(1,1) private finding", $rows[2][6]);
        $this->assertSame("'=Addressed after package upgrade", $rows[2][9]);
        $this->assertStringNotContainsString($reporter->email, $response->streamedContent());
        $this->assertStringNotContainsString($otherReporter->email, $response->streamedContent());
        $this->assertStringNotContainsString('Wrong reason feedback.', $response->streamedContent());
        $this->assertStringNotContainsString('Foreign feedback.', $response->streamedContent());
        $this->assertStringNotContainsString('Outside date feedback.', $response->streamedContent());
    }

    public function test_contributor_can_reopen_a_resolved_report_from_the_inbox(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Reopened recipe');
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'misleading',
            'resolved_at' => now(),
        ]);

        $this->actingAs($author)
            ->from(route('gallery.reports.index', ['status' => 'resolved']))
            ->patch(route('gallery.reports.reopen', [$recipe, $report]))
            ->assertRedirect(route('gallery.reports.index', ['status' => 'resolved']))
            ->assertSessionHas('status', 'The community report was reopened.');

        $this->assertNull($report->refresh()->resolved_at);
        $this->assertSame(
            'A community report for gallery recipe "Reopened recipe" was reopened.',
            $author->events()->latest('id')->value('event'),
        );
    }

    public function test_contributor_can_resolve_selected_reports_with_anonymous_recipe_audits(): void
    {
        [$firstReporter, $secondReporter, $thirdReporter, $author] = User::factory()->count(4)->create();
        $firstRecipe = $this->recipe($author, 'Bulk first recipe');
        $secondRecipe = $this->recipe($author, 'Bulk second recipe');
        $first = $firstReporter->recipeReports()->create([
            'recipe_id' => $firstRecipe->id,
            'reason' => 'security',
        ]);
        $second = $secondReporter->recipeReports()->create([
            'recipe_id' => $firstRecipe->id,
            'reason' => 'broken',
        ]);
        $alreadyResolved = $thirdReporter->recipeReports()->create([
            'recipe_id' => $secondRecipe->id,
            'reason' => 'outdated',
            'resolved_at' => now(),
        ]);

        $this->actingAs($author)
            ->from(route('gallery.reports.index'))
            ->patch(route('gallery.reports.resolve-many'), [
                'reports' => [$second->id, $alreadyResolved->id, $first->id],
            ])
            ->assertRedirect(route('gallery.reports.index'))
            ->assertSessionHas('status', '2 community reports were marked as resolved.');

        $this->assertNotNull($first->refresh()->resolved_at);
        $this->assertNotNull($second->refresh()->resolved_at);
        $this->assertNotNull($alreadyResolved->refresh()->resolved_at);
        $this->assertSame([
            '2 community reports for gallery recipe "Bulk first recipe" were resolved.',
        ], $author->events()->pluck('event')->all());
        $this->assertStringNotContainsString($firstReporter->name, $author->events()->value('event'));
        $this->assertStringNotContainsString($secondReporter->name, $author->events()->value('event'));
    }

    public function test_contributor_can_reopen_selected_reports_with_notes_audits_and_notifications(): void
    {
        [$firstReporter, $secondReporter, $thirdReporter, $author] = User::factory()->count(4)->create();
        $firstRecipe = $this->recipe($author, 'Bulk reopen first recipe');
        $secondRecipe = $this->recipe($author, 'Bulk reopen second recipe');
        $first = $firstReporter->recipeReports()->create([
            'recipe_id' => $firstRecipe->id,
            'reason' => 'security',
            'resolved_at' => now(),
            'resolution_note' => 'First stale resolution.',
        ]);
        $second = $secondReporter->recipeReports()->create([
            'recipe_id' => $firstRecipe->id,
            'reason' => 'broken',
            'resolved_at' => now(),
            'resolution_note' => 'Second stale resolution.',
        ]);
        $alreadyOpen = $thirdReporter->recipeReports()->create([
            'recipe_id' => $secondRecipe->id,
            'reason' => 'outdated',
        ]);

        $this->actingAs($author)
            ->from(route('gallery.reports.index', ['status' => 'all']))
            ->patch(route('gallery.reports.reopen-many'), [
                'reports' => [$second->id, $alreadyOpen->id, $first->id],
            ])
            ->assertRedirect(route('gallery.reports.index', ['status' => 'all']))
            ->assertSessionHas('status', '2 community reports were reopened.');

        $this->assertNull($first->refresh()->resolved_at);
        $this->assertNull($first->resolution_note);
        $this->assertNull($second->refresh()->resolved_at);
        $this->assertNull($second->resolution_note);
        $this->assertNull($alreadyOpen->refresh()->resolved_at);
        $this->assertSame([
            '2 community reports for gallery recipe "Bulk reopen first recipe" were reopened.',
        ], $author->events()->pluck('event')->all());
        $this->assertSame(2, $author->unreadNotifications()->count());
        $this->assertSame('Gallery report reopened', $firstReporter->unreadNotifications()->sole()->data['title']);
        $this->assertSame('Gallery report reopened', $secondReporter->unreadNotifications()->sole()->data['title']);
        $this->assertSame(0, $thirdReporter->notifications()->count());
        $this->assertStringNotContainsString($firstReporter->name, $author->events()->value('event'));
        $this->assertStringNotContainsString('stale resolution', $author->events()->value('event'));
    }

    public function test_bulk_review_actions_roll_back_every_report_notification_and_audit_on_failure(): void
    {
        [$firstReporter, $secondReporter, $author] = User::factory()->count(3)->create();
        $recipe = $this->recipe($author, 'Atomic bulk review recipe');
        $first = $firstReporter->recipeReports()->create(['recipe_id' => $recipe->id, 'reason' => 'security']);
        $second = $secondReporter->recipeReports()->create(['recipe_id' => $recipe->id, 'reason' => 'broken']);
        $reportIds = [$first->id, $second->id];
        DB::statement("CREATE TRIGGER fail_bulk_resolution_audit BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT, 'forced bulk resolution audit failure'); END");

        try {
            $this->actingAs($author)->patch(route('gallery.reports.resolve-many'), [
                'reports' => $reportIds,
            ])->assertInternalServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_bulk_resolution_audit');
        }

        $this->assertNull($first->refresh()->resolved_at);
        $this->assertNull($second->refresh()->resolved_at);
        $this->assertSame(0, $firstReporter->notifications()->count());
        $this->assertSame(0, $secondReporter->notifications()->count());
        $this->assertSame(0, $author->events()->count());

        $this->actingAs($author)->patch(route('gallery.reports.resolve-many'), ['reports' => $reportIds]);
        $this->assertNotNull($first->refresh()->resolved_at);
        $this->assertNotNull($second->refresh()->resolved_at);
        $authorEventCount = $author->events()->count();
        DB::statement("CREATE TRIGGER fail_bulk_reopen_audit BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT, 'forced bulk reopen audit failure'); END");

        try {
            $this->actingAs($author)->patch(route('gallery.reports.reopen-many'), [
                'reports' => $reportIds,
            ])->assertInternalServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_bulk_reopen_audit');
        }

        $this->assertNotNull($first->refresh()->resolved_at);
        $this->assertNotNull($second->refresh()->resolved_at);
        $this->assertSame(0, $author->unreadNotifications()->count());
        $this->assertSame(1, $firstReporter->unreadNotifications()->count());
        $this->assertSame(1, $secondReporter->unreadNotifications()->count());
        $this->assertSame($authorEventCount, $author->events()->count());
    }

    public function test_bulk_reopen_is_atomic_when_any_selected_report_is_not_owned(): void
    {
        [$reporter, $foreignReporter, $author, $otherAuthor] = User::factory()->count(4)->create();
        $ownedRecipe = $this->recipe($author, 'Owned resolved recipe');
        $foreignRecipe = $this->recipe($otherAuthor, 'Foreign resolved recipe');
        $owned = $reporter->recipeReports()->create([
            'recipe_id' => $ownedRecipe->id,
            'reason' => 'broken',
            'resolved_at' => now(),
            'resolution_note' => 'Keep this note.',
        ]);
        $foreign = $foreignReporter->recipeReports()->create([
            'recipe_id' => $foreignRecipe->id,
            'reason' => 'security',
            'resolved_at' => now(),
        ]);

        $this->actingAs($author)
            ->patch(route('gallery.reports.reopen-many'), [
                'reports' => [$owned->id, $foreign->id],
            ])
            ->assertNotFound();

        $this->assertNotNull($owned->refresh()->resolved_at);
        $this->assertSame('Keep this note.', $owned->resolution_note);
        $this->assertNotNull($foreign->refresh()->resolved_at);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_bulk_reopen_requires_a_bounded_distinct_selection(): void
    {
        $author = User::factory()->create();

        $this->actingAs($author)
            ->patch(route('gallery.reports.reopen-many'), ['reports' => []])
            ->assertSessionHasErrors('reports', errorBag: 'bulkReopen');
        $this->actingAs($author)
            ->patch(route('gallery.reports.reopen-many'), ['reports' => range(1, 21)])
            ->assertSessionHasErrors('reports', errorBag: 'bulkReopen');
        $this->actingAs($author)
            ->patch(route('gallery.reports.reopen-many'), ['reports' => [1, 1]])
            ->assertSessionHasErrors('reports.1', errorBag: 'bulkReopen');
    }

    public function test_bulk_resolution_rejects_the_complete_selection_when_any_report_is_not_owned(): void
    {
        [$reporter, $foreignReporter, $author, $otherAuthor] = User::factory()->count(4)->create();
        $ownedRecipe = $this->recipe($author, 'Atomic owned recipe');
        $foreignRecipe = $this->recipe($otherAuthor, 'Atomic foreign recipe');
        $owned = $reporter->recipeReports()->create([
            'recipe_id' => $ownedRecipe->id,
            'reason' => 'broken',
        ]);
        $foreign = $foreignReporter->recipeReports()->create([
            'recipe_id' => $foreignRecipe->id,
            'reason' => 'security',
        ]);

        $this->actingAs($author)
            ->patch(route('gallery.reports.resolve-many'), [
                'reports' => [$owned->id, $foreign->id],
            ])
            ->assertNotFound();

        $this->assertNull($owned->refresh()->resolved_at);
        $this->assertNull($foreign->refresh()->resolved_at);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_bulk_resolution_requires_a_bounded_distinct_selection(): void
    {
        $author = User::factory()->create();

        $this->actingAs($author)
            ->patch(route('gallery.reports.resolve-many'), ['reports' => []])
            ->assertSessionHasErrors('reports', errorBag: 'bulkResolve');
        $this->actingAs($author)
            ->patch(route('gallery.reports.resolve-many'), ['reports' => range(1, 21)])
            ->assertSessionHasErrors('reports', errorBag: 'bulkResolve');
        $this->actingAs($author)
            ->patch(route('gallery.reports.resolve-many'), ['reports' => [1, 1]])
            ->assertSessionHasErrors('reports.1', errorBag: 'bulkResolve');
    }

    public function test_feedback_inbox_requires_authentication_and_reopen_requires_ownership(): void
    {
        [$reporter, $intruder, $author] = User::factory()->count(3)->create();
        $recipe = $this->recipe($author, 'Private inbox recipe');
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'other',
            'resolved_at' => now(),
        ]);

        $this->get(route('gallery.reports.index'))->assertRedirect(route('login'));
        $this->get(route('gallery.reports.export'))->assertRedirect(route('login'));
        $this->patch(route('gallery.reports.resolve-many'), ['reports' => [$report->id]])->assertRedirect(route('login'));
        $this->patch(route('gallery.reports.reopen-many'), ['reports' => [$report->id]])->assertRedirect(route('login'));
        $this->actingAs($intruder)
            ->patch(route('gallery.reports.reopen', [$recipe, $report]))
            ->assertNotFound();
        $this->assertNotNull($report->refresh()->resolved_at);
    }

    public function test_gallery_keeps_the_feedback_inbox_discoverable_without_open_reports(): void
    {
        $author = User::factory()->create();

        $this->actingAs($author)->get(route('gallery.index'))
            ->assertSuccessful()
            ->assertSee('Feedback Inbox')
            ->assertSee(route('gallery.reports.index'));
    }

    private function recipe(User $author, string $name): Recipe
    {
        return $author->recipes()->create([
            'name' => $name,
            'description' => "Description for {$name}.",
            'script' => 'echo feedback-inbox',
            'category' => 'security',
            'is_published' => true,
            'published_at' => now(),
            'gallery_revision_at' => now(),
        ]);
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+b');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
