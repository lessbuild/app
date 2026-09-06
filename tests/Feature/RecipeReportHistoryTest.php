<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Models\User;
use App\Notifications\RecipeReportStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecipeReportHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporter_history_is_owner_scoped_includes_unpublished_recipes_and_omits_sensitive_text(): void
    {
        [$reporter, $foreignReporter, $author, $otherAuthor] = User::factory()->count(4)->create();
        $published = $this->recipe($author, 'Published report history', true);
        $unpublished = $this->recipe($author, 'Unpublished report history', false);
        $foreign = $this->recipe($otherAuthor, 'Foreign report history', true);
        $open = $reporter->recipeReports()->create([
            'recipe_id' => $published->id,
            'reason' => 'broken',
            'details' => 'open-history-secret',
        ]);
        $resolved = $reporter->recipeReports()->create([
            'recipe_id' => $unpublished->id,
            'reason' => 'security',
            'details' => 'resolved-history-secret',
            'resolved_at' => now(),
            'resolution_note' => 'resolution-history-secret',
        ]);
        $foreignReporter->recipeReports()->create([
            'recipe_id' => $foreign->id,
            'reason' => 'other',
            'details' => 'foreign-history-secret',
        ]);

        $this->actingAs($reporter)->get(route('gallery.reports.mine'))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'search' => null,
                'status' => 'all',
                'availability' => 'all',
                'updates' => 'all',
                'reason' => null,
                'sort' => 'newest',
            ])
            ->assertViewHas('metrics', [
                'matching' => 2,
                'open' => 1,
                'resolved' => 1,
                'unpublished' => 1,
                'unread_updates' => 0,
            ])
            ->assertViewHas('reports', function ($reports) use ($open, $resolved): bool {
                if ($reports->pluck('id')->sort()->values()->all() !== collect([$open->id, $resolved->id])->sort()->values()->all()) {
                    return false;
                }

                return $reports->every(fn (RecipeReport $report): bool => ! array_key_exists('details', $report->getAttributes())
                    && ! array_key_exists('resolution_note', $report->getAttributes())
                    && ! array_key_exists('script', $report->recipe->getAttributes())
                    && ! array_key_exists('description', $report->recipe->getAttributes()));
            })
            ->assertSee('My Community Reports')
            ->assertSee('Published report history')
            ->assertSee('Unpublished report history')
            ->assertSee('No longer published')
            ->assertSee(route('gallery.report.status', $open))
            ->assertSee(route('gallery.report.status', $resolved))
            ->assertSee(route('gallery.reports.mine.export', [
                'status' => 'all',
                'availability' => 'all',
                'updates' => 'all',
                'sort' => 'newest',
            ]))
            ->assertDontSee('Foreign report history')
            ->assertDontSee('history-secret', false)
            ->assertDontSee('report-history-script', false);

        $this->actingAs($reporter)->get(route('gallery.index'))
            ->assertSuccessful()
            ->assertSee('My Reports')
            ->assertSee(route('gallery.reports.mine'));
    }

    public function test_reporter_history_combines_filters_and_normalizes_invalid_values(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $published = $this->recipe($author, 'Published matching report', true);
        $unpublished = $this->recipe($author, 'Unpublished matching report', false);
        $reporter->recipeReports()->create([
            'recipe_id' => $published->id,
            'reason' => 'broken',
        ]);
        $resolved = $reporter->recipeReports()->create([
            'recipe_id' => $unpublished->id,
            'reason' => 'security',
            'resolved_at' => now(),
        ]);

        $this->actingAs($reporter)->get(route('gallery.reports.mine', [
            'search' => '  Unpublished matching  ',
            'status' => 'resolved',
            'availability' => 'unpublished',
            'updates' => 'reviewed',
            'reason' => 'security',
            'sort' => 'updated',
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'search' => 'Unpublished matching',
                'status' => 'resolved',
                'availability' => 'unpublished',
                'updates' => 'reviewed',
                'reason' => 'security',
                'sort' => 'updated',
            ])
            ->assertViewHas('metrics', [
                'matching' => 1,
                'open' => 0,
                'resolved' => 1,
                'unpublished' => 1,
                'unread_updates' => 0,
            ])
            ->assertViewHas('reports', fn ($reports): bool => $reports->sole()->id === $resolved->id)
            ->assertSee('Unpublished matching report')
            ->assertDontSee('Published matching report');

        $this->actingAs($reporter)->get(route('gallery.reports.mine', [
            'search' => '   ',
            'status' => 'deleted',
            'availability' => 'missing',
            'updates' => 'invalid',
            'reason' => 'invalid',
            'sort' => 'random',
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'search' => null,
                'status' => 'all',
                'availability' => 'all',
                'updates' => 'all',
                'reason' => null,
                'sort' => 'newest',
            ]);
    }

    public function test_reporter_history_is_paginated_and_preserves_filters(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        foreach (range(1, 21) as $number) {
            $recipe = $this->recipe($author, "History item {$number}", true);
            $reporter->recipeReports()->create([
                'recipe_id' => $recipe->id,
                'reason' => 'outdated',
            ]);
        }

        $response = $this->actingAs($reporter)->get(route('gallery.reports.mine', [
            'search' => 'History item',
            'status' => 'open',
            'availability' => 'published',
            'updates' => 'all',
            'reason' => 'outdated',
            'sort' => 'oldest',
        ]));

        $response->assertSuccessful()->assertViewHas('metrics', [
            'matching' => 21,
            'open' => 21,
            'resolved' => 0,
            'unpublished' => 0,
            'unread_updates' => 0,
        ]);
        $reports = $response->viewData('reports');
        $this->assertCount(20, $reports);
        parse_str((string) parse_url($reports->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('History item', $query['search']);
        $this->assertSame('open', $query['status']);
        $this->assertSame('published', $query['availability']);
        $this->assertSame('all', $query['updates']);
        $this->assertSame('outdated', $query['reason']);
        $this->assertSame('oldest', $query['sort']);
    }

    public function test_reporter_can_review_owner_scoped_unread_updates_from_history_or_status(): void
    {
        [$reporter, $foreignReporter, $author, $otherAuthor] = User::factory()->count(4)->create();
        $recipe = $this->recipe($author, 'Updated report history', true);
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'resolved_at' => now(),
            'resolution_note' => 'The contributor fixed the issue.',
        ]);
        $reporter->notify(new RecipeReportStatusNotification($recipe, $report, 'resolved'));
        $notification = $reporter->unreadNotifications()->sole();
        $reviewedRecipe = $this->recipe($author, 'Already reviewed history', true);
        $reviewedReport = $reporter->recipeReports()->create([
            'recipe_id' => $reviewedRecipe->id,
            'reason' => 'outdated',
            'resolved_at' => now(),
        ]);

        $foreignRecipe = $this->recipe($otherAuthor, 'Foreign updated history', true);
        $foreignReport = $foreignReporter->recipeReports()->create([
            'recipe_id' => $foreignRecipe->id,
            'reason' => 'broken',
            'resolved_at' => now(),
        ]);
        $foreignReporter->notify(new RecipeReportStatusNotification($foreignRecipe, $foreignReport, 'resolved'));
        $foreignNotification = $foreignReporter->unreadNotifications()->sole();

        $this->actingAs($reporter)->get(route('gallery.reports.mine'))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['unread_updates'] === 1)
            ->assertSee('New update')
            ->assertSee('Review new update')
            ->assertSee('Review 1 update')
            ->assertSee(route('gallery.reports.mine.review-updates'))
            ->assertSee(route('notifications.read', $notification))
            ->assertDontSee('Foreign updated history')
            ->assertDontSee(route('notifications.read', $foreignNotification));

        $this->actingAs($reporter)->get(route('gallery.reports.mine', ['updates' => 'unread']))
            ->assertSuccessful()
            ->assertViewHas('reports', fn ($reports): bool => $reports->sole()->is($report))
            ->assertSee('Updated report history')
            ->assertDontSee('Already reviewed history');
        $this->actingAs($reporter)->get(route('gallery.reports.mine', ['updates' => 'reviewed']))
            ->assertSuccessful()
            ->assertViewHas('reports', fn ($reports): bool => $reports->sole()->is($reviewedReport))
            ->assertSee('Already reviewed history')
            ->assertDontSee('Updated report history');
        $unreadExport = $this->actingAs($reporter)->get(route('gallery.reports.mine.export', ['updates' => 'unread']))
            ->streamedContent();
        $this->assertStringContainsString('Updated report history', $unreadExport);
        $this->assertStringNotContainsString('Already reviewed history', $unreadExport);

        $this->actingAs($reporter)->get(route('gallery.report.status', $report))
            ->assertSuccessful()
            ->assertSee('New contributor update')
            ->assertSee('Mark update reviewed')
            ->assertSee(route('notifications.read', $notification));
        $this->assertNull($notification->refresh()->read_at);

        $this->actingAs($reporter)->post(route('notifications.read', $notification))
            ->assertRedirect(route('gallery.report.status', $report));
        $this->assertNotNull($notification->refresh()->read_at);
        $this->assertNull($foreignNotification->refresh()->read_at);

        $this->actingAs($reporter)->get(route('gallery.reports.mine'))
            ->assertSuccessful()
            ->assertDontSee('New update')
            ->assertSee('View report status');
        $this->actingAs($reporter)->get(route('gallery.report.status', $report))
            ->assertSuccessful()
            ->assertDontSee('New contributor update')
            ->assertDontSee('Mark update reviewed');

        $reporter->notify(new RecipeReportStatusNotification($recipe, $report, 'reopened'));
        $secondUpdate = $reporter->unreadNotifications()
            ->where('data->category', 'gallery')
            ->sole();
        $unrelated = $reporter->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'Unrelated test notification',
            'data' => [
                'category' => 'recipe',
                'report_id' => $report->id,
                'title' => 'Unrelated alert',
            ],
        ]);

        $this->actingAs($reporter)->post(route('gallery.reports.mine.review-updates'))
            ->assertRedirect()
            ->assertSessionHas('status', '1 report update was marked as reviewed.');
        $this->assertNotNull($secondUpdate->refresh()->read_at);
        $this->assertNull($unrelated->refresh()->read_at);
        $this->assertNull($foreignNotification->refresh()->read_at);

        $this->actingAs($reporter)->post(route('gallery.reports.mine.review-updates'))
            ->assertRedirect()
            ->assertSessionHas('status', 'There are no unread report updates.');
    }

    public function test_filtered_reporter_history_export_is_owner_scoped_unpublished_and_spreadsheet_safe(): void
    {
        [$reporter, $foreignReporter, $author, $otherAuthor] = User::factory()->count(4)->create();
        $recipe = $this->recipe($author, '=HYPERLINK("https://example.test")', false);
        $foreignRecipe = $this->recipe($otherAuthor, 'Foreign export history', false);
        $report = $reporter->recipeReports()->create([
            'recipe_id' => $recipe->id,
            'reason' => 'security',
            'details' => "+SUM(1,1)\0 reporter evidence",
            'resolved_at' => '2026-09-04 12:00:00',
            'resolution_note' => '@Contributor response',
        ]);
        $foreignReporter->recipeReports()->create([
            'recipe_id' => $foreignRecipe->id,
            'reason' => 'security',
            'details' => 'Foreign export secret',
            'resolved_at' => now(),
        ]);

        $response = $this->actingAs($reporter)->get(route('gallery.reports.mine.export', [
            'search' => 'HYPERLINK',
            'status' => 'resolved',
            'availability' => 'unpublished',
            'reason' => 'security',
            'sort' => 'oldest',
        ]));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment; filename=lessbuild-my-community-reports-', (string) $response->headers->get('content-disposition'));
        $content = $response->streamedContent();
        $rows = $this->csvRows($content);
        $this->assertSame([
            'Report ID',
            'Recipe ID',
            'Recipe',
            'Category',
            'Recipe availability',
            'Issue type',
            'Report status',
            'Details',
            'Resolution note',
            'Reported at',
            'Resolved at',
            'Updated at',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $report->id, $rows[1][0]);
        $this->assertSame((string) $recipe->id, $rows[1][1]);
        $this->assertSame("'=HYPERLINK(\"https://example.test\")", $rows[1][2]);
        $this->assertSame('unpublished', $rows[1][4]);
        $this->assertSame('resolved', $rows[1][6]);
        $this->assertSame("'+SUM(1,1) reporter evidence", $rows[1][7]);
        $this->assertSame("'@Contributor response", $rows[1][8]);
        $this->assertStringNotContainsString('Foreign export secret', $content);
        $this->assertStringNotContainsString($reporter->email, $content);
        $this->assertStringNotContainsString($foreignReporter->email, $content);
        $this->assertStringNotContainsString('report-history-script', $content);
    }

    public function test_reporter_history_supports_deterministic_screen_and_export_ordering(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $firstRecipe = $this->recipe($author, 'First ordered report', true);
        $secondRecipe = $this->recipe($author, 'Second ordered report', true);
        $thirdRecipe = $this->recipe($author, 'Third ordered report', true);
        $first = $reporter->recipeReports()->create(['recipe_id' => $firstRecipe->id, 'reason' => 'broken']);
        $second = $reporter->recipeReports()->create(['recipe_id' => $secondRecipe->id, 'reason' => 'broken']);
        $third = $reporter->recipeReports()->create(['recipe_id' => $thirdRecipe->id, 'reason' => 'broken']);
        $first->forceFill(['created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-03 10:00:00'])->saveQuietly();
        $second->forceFill(['created_at' => '2026-08-02 10:00:00', 'updated_at' => '2026-08-01 10:00:00'])->saveQuietly();
        $third->forceFill(['created_at' => '2026-08-03 10:00:00', 'updated_at' => '2026-08-02 10:00:00'])->saveQuietly();

        $this->actingAs($reporter)->get(route('gallery.reports.mine', ['sort' => 'newest']))
            ->assertSeeInOrder(['Third ordered report', 'Second ordered report', 'First ordered report']);
        $this->actingAs($reporter)->get(route('gallery.reports.mine', ['sort' => 'oldest']))
            ->assertSeeInOrder(['First ordered report', 'Second ordered report', 'Third ordered report']);
        $this->actingAs($reporter)->get(route('gallery.reports.mine', ['sort' => 'updated']))
            ->assertSeeInOrder(['First ordered report', 'Third ordered report', 'Second ordered report']);

        $rows = $this->csvRows($this->actingAs($reporter)->get(route('gallery.reports.mine.export', [
            'reason' => 'broken',
            'sort' => 'updated',
        ]))->streamedContent());
        $this->assertSame([(string) $first->id, (string) $third->id, (string) $second->id], [
            $rows[1][0],
            $rows[2][0],
            $rows[3][0],
        ]);
    }

    public function test_reporter_history_requires_authentication(): void
    {
        $this->get(route('gallery.reports.mine'))->assertRedirect(route('login'));
        $this->get(route('gallery.reports.mine.export'))->assertRedirect(route('login'));
        $this->post(route('gallery.reports.mine.review-updates'))->assertRedirect(route('login'));
    }

    private function recipe(User $author, string $name, bool $published): Recipe
    {
        return $author->recipes()->create([
            'name' => $name,
            'description' => "Description for {$name}.",
            'script' => 'echo report-history-script',
            'category' => 'security',
            'is_published' => $published,
            'published_at' => $published ? now() : null,
            'gallery_revision_at' => $published ? now() : null,
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
