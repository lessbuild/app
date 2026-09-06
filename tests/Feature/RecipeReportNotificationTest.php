<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipeReportNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_report_creates_one_anonymous_contributor_notification(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Notification helper');

        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), [
            'reason' => 'security',
            'details' => 'private reporter finding',
        ])->assertRedirect();

        $notification = $author->unreadNotifications()->sole();
        $report = $reporter->recipeReports()->sole();
        $this->assertSame([
            'category' => 'recipe',
            'resource_id' => $report->id,
            'title' => 'Community recipe feedback',
            'message' => '"Notification helper" has a community report that needs review.',
            'status' => 'info',
        ], $notification->data);
        $serialized = json_encode($notification->data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($reporter->name, $serialized);
        $this->assertStringNotContainsString($reporter->email, $serialized);
        $this->assertStringNotContainsString('private reporter finding', $serialized);
        $this->assertSame(0, $reporter->notifications()->count());

        $this->actingAs($author)->get(route('notifications.index', ['category' => 'recipe']))
            ->assertSuccessful()
            ->assertSee('Community recipe feedback')
            ->assertDontSee($reporter->email);
        $this->actingAs($author)->post(route('notifications.read', $notification->id))
            ->assertRedirect(route('gallery.reports.index', [
                'status' => 'all',
                'report' => $report->id,
            ]).'#report-'.$report->id);
    }

    public function test_report_updates_deduplicate_open_alerts_and_renotify_after_manual_read(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Updated feedback helper');

        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'broken']);
        $first = $author->unreadNotifications()->sole();

        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'outdated']);
        $this->assertSame(1, $author->notifications()->count());
        $this->assertSame(1, $author->unreadNotifications()->count());

        $first->markAsRead();
        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'misleading']);
        $this->assertSame(2, $author->notifications()->count());
        $this->assertSame(1, $author->unreadNotifications()->count());
    }

    public function test_sqlite_submission_reserves_the_writer_before_reading_the_unique_report(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'SQLite reservation helper');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'broken']);

        $reservationIndex = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'update "recipes" set "id" = id'));
        $reportLookupIndex = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "recipe_reports"')
            && str_contains($sql, '"recipe_id" = ?')
            && str_contains($sql, 'limit 1'));
        $this->assertIsInt($reservationIndex);
        $this->assertIsInt($reportLookupIndex);
        $this->assertLessThan($reportLookupIndex, $reservationIndex);
        $this->assertDatabaseCount('recipe_reports', 1);
    }

    public function test_resolution_and_reopen_keep_history_until_withdrawal_removes_dead_links(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Lifecycle feedback helper');
        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'broken']);
        $report = $reporter->recipeReports()->sole();

        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]));
        $this->assertSame(0, $author->unreadNotifications()->count());
        $this->assertSame(1, $author->notifications()->count());

        $this->actingAs($author)->patch(route('gallery.reports.reopen', [$recipe, $report]));
        $this->assertSame(1, $author->unreadNotifications()->count());
        $this->assertSame(2, $author->notifications()->count());
        $this->assertSame(2, $reporter->notifications()->count());

        $this->actingAs($reporter)->delete(route('gallery.report.destroy', $recipe));
        $this->assertSame(0, $author->unreadNotifications()->count());
        $this->assertSame(0, $author->notifications()->count());
        $this->assertSame(0, $reporter->notifications()->count());
        $this->assertDatabaseCount('recipe_reports', 0);
    }

    public function test_recipe_deletion_removes_only_notifications_for_its_cascading_reports(): void
    {
        [$firstReporter, $secondReporter, $author] = User::factory()->count(3)->create();
        $deletedRecipe = $this->recipe($author, 'Deleted notification recipe');
        $retainedRecipe = $this->recipe($author, 'Retained notification recipe');
        $this->actingAs($firstReporter)->post(route('gallery.report.store', $deletedRecipe), ['reason' => 'security']);
        $this->actingAs($secondReporter)->post(route('gallery.report.store', $retainedRecipe), ['reason' => 'broken']);
        $deletedReport = $firstReporter->recipeReports()->sole();
        $retainedReport = $secondReporter->recipeReports()->sole();
        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$deletedRecipe, $deletedReport]));
        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$retainedRecipe, $retainedReport]));

        $this->assertSame(2, $author->notifications()->count());
        $this->assertSame(1, $firstReporter->notifications()->count());
        $this->assertSame(1, $secondReporter->notifications()->count());

        $this->actingAs($author)->delete(route('recipes.destroy', $deletedRecipe))->assertRedirect();

        $this->assertDatabaseMissing('recipe_reports', ['id' => $deletedReport->id]);
        $this->assertDatabaseHas('recipe_reports', ['id' => $retainedReport->id]);
        $this->assertSame(1, $author->notifications()->count());
        $this->assertSame($retainedReport->id, $author->notifications()->sole()->data['resource_id']);
        $this->assertSame(0, $firstReporter->notifications()->count());
        $this->assertSame(1, $secondReporter->notifications()->count());
        $this->assertSame($retainedReport->id, $secondReporter->notifications()->sole()->data['report_id']);
    }

    public function test_failed_withdrawal_restores_the_report_notifications_and_audit_history(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Atomic withdrawal helper');
        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'security']);
        $report = $reporter->recipeReports()->sole();
        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]));
        $this->actingAs($author)->patch(route('gallery.reports.reopen', [$recipe, $report]));

        $authorNotificationCount = $author->notifications()->count();
        $reporterNotificationCount = $reporter->notifications()->count();
        $reporterEventCount = $reporter->events()->count();
        DB::statement("CREATE TRIGGER fail_withdrawal_audit BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT, 'forced withdrawal audit failure'); END");

        try {
            $this->actingAs($reporter)
                ->delete(route('gallery.report.destroy', $recipe))
                ->assertInternalServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_withdrawal_audit');
        }

        $this->assertDatabaseHas('recipe_reports', ['id' => $report->id]);
        $this->assertSame($authorNotificationCount, $author->notifications()->count());
        $this->assertSame($reporterNotificationCount, $reporter->notifications()->count());
        $this->assertSame($reporterEventCount, $reporter->events()->count());
    }

    public function test_failed_recipe_deletion_restores_the_recipe_report_notifications_and_audit_history(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Atomic recipe deletion helper');
        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'broken']);
        $report = $reporter->recipeReports()->sole();
        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]));

        $authorNotificationCount = $author->notifications()->count();
        $reporterNotificationCount = $reporter->notifications()->count();
        $authorEventCount = $author->events()->count();
        DB::statement("CREATE TRIGGER fail_recipe_deletion_audit BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT, 'forced recipe deletion audit failure'); END");

        try {
            $this->actingAs($author)
                ->delete(route('recipes.destroy', $recipe))
                ->assertInternalServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_recipe_deletion_audit');
        }

        $this->assertDatabaseHas('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseHas('recipe_reports', ['id' => $report->id]);
        $this->assertSame($authorNotificationCount, $author->notifications()->count());
        $this->assertSame($reporterNotificationCount, $reporter->notifications()->count());
        $this->assertSame($authorEventCount, $author->events()->count());
    }

    public function test_failed_report_submission_rolls_back_the_report_notification_and_audit(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Atomic submission helper');
        DB::statement("CREATE TRIGGER fail_report_submission_audit BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT, 'forced report submission audit failure'); END");

        try {
            $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), [
                'reason' => 'security',
                'details' => 'Must not survive a partial submission.',
            ])->assertInternalServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_report_submission_audit');
        }

        $this->assertDatabaseCount('recipe_reports', 0);
        $this->assertSame(0, $author->notifications()->count());
        $this->assertSame(0, $reporter->events()->count());
    }

    public function test_failed_resolution_rolls_back_state_notifications_and_audit(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Atomic resolution helper');
        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'broken']);
        $report = $reporter->recipeReports()->sole();
        $authorEventCount = $author->events()->count();
        DB::statement("CREATE TRIGGER fail_report_resolution_audit BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT, 'forced report resolution audit failure'); END");

        try {
            $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]), [
                'resolution_note' => 'Must be rolled back.',
            ])->assertInternalServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_report_resolution_audit');
        }

        $this->assertNull($report->refresh()->resolved_at);
        $this->assertNull($report->resolution_note);
        $this->assertSame(1, $author->unreadNotifications()->count());
        $this->assertSame(0, $reporter->notifications()->count());
        $this->assertSame($authorEventCount, $author->events()->count());
    }

    public function test_failed_resolution_note_update_rolls_back_note_notifications_and_audit(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Atomic note helper');
        $report = $reporter->recipeReports()->create(['recipe_id' => $recipe->id, 'reason' => 'outdated']);
        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]), [
            'resolution_note' => 'Original note.',
        ]);
        $authorEventCount = $author->events()->count();
        $reporterNotification = $reporter->unreadNotifications()->sole();
        DB::statement("CREATE TRIGGER fail_resolution_note_audit BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT, 'forced resolution note audit failure'); END");

        try {
            $this->actingAs($author)->patch(route('gallery.reports.resolution-note.update', [$recipe, $report]), [
                'resolution_note' => 'Replacement that must roll back.',
            ])->assertInternalServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_resolution_note_audit');
        }

        $this->assertSame('Original note.', $report->refresh()->resolution_note);
        $this->assertSame(1, $reporter->notifications()->count());
        $this->assertNull($reporterNotification->refresh()->read_at);
        $this->assertSame($authorEventCount, $author->events()->count());
    }

    public function test_failed_reopen_rolls_back_state_notifications_and_audit(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Atomic reopen helper');
        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'misleading']);
        $report = $reporter->recipeReports()->sole();
        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]), [
            'resolution_note' => 'Keep this resolution.',
        ]);
        $authorEventCount = $author->events()->count();
        $reporterNotification = $reporter->unreadNotifications()->sole();
        DB::statement("CREATE TRIGGER fail_report_reopen_audit BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT, 'forced report reopen audit failure'); END");

        try {
            $this->actingAs($author)
                ->patch(route('gallery.reports.reopen', [$recipe, $report]))
                ->assertInternalServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_report_reopen_audit');
        }

        $this->assertNotNull($report->refresh()->resolved_at);
        $this->assertSame('Keep this resolution.', $report->resolution_note);
        $this->assertSame(0, $author->unreadNotifications()->count());
        $this->assertSame(1, $reporter->notifications()->count());
        $this->assertNull($reporterNotification->refresh()->read_at);
        $this->assertSame($authorEventCount, $author->events()->count());
    }

    public function test_repeated_review_requests_are_idempotent_across_state_notifications_and_audits(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Idempotent review helper');
        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'security']);
        $report = $reporter->recipeReports()->sole();

        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]), [
            'resolution_note' => 'Reviewed once.',
        ]);
        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]), [
            'resolution_note' => 'A repeated resolve must not overwrite the note.',
        ]);
        $this->actingAs($author)->patch(route('gallery.reports.resolution-note.update', [$recipe, $report]), [
            'resolution_note' => 'Reviewed once.',
        ])->assertSessionHas('status', 'The resolution note is unchanged.');

        $this->assertSame('Reviewed once.', $report->refresh()->resolution_note);
        $this->assertSame(1, $author->events()->count());
        $this->assertSame(1, $reporter->notifications()->count());
        $this->assertSame(1, $author->notifications()->count());
        $this->assertSame(0, $author->unreadNotifications()->count());

        $this->actingAs($author)->patch(route('gallery.reports.reopen', [$recipe, $report]));
        $this->actingAs($author)->patch(route('gallery.reports.reopen', [$recipe, $report]));

        $this->assertNull($report->refresh()->resolved_at);
        $this->assertNull($report->resolution_note);
        $this->assertSame(2, $author->events()->count());
        $this->assertSame(2, $reporter->notifications()->count());
        $this->assertSame(2, $author->notifications()->count());
        $this->assertSame(1, $author->unreadNotifications()->count());
    }

    public function test_contributor_notification_history_still_targets_the_report_after_resolution(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Historical notification helper');
        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), ['reason' => 'broken']);
        $report = $reporter->recipeReports()->sole();
        $notification = $author->notifications()->sole();

        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]));

        $this->assertNotNull($notification->refresh()->read_at);
        $destination = route('gallery.reports.index', [
            'status' => 'all',
            'report' => $report->id,
        ]).'#report-'.$report->id;
        $this->actingAs($author)->post(route('notifications.read', $notification->id))
            ->assertRedirect($destination);
        $this->actingAs($author)->get($destination)
            ->assertSuccessful()
            ->assertSee('id="report-'.$report->id.'"', false)
            ->assertSee('Resolved');
    }

    public function test_bulk_resolution_acknowledges_only_selected_recipe_report_alerts(): void
    {
        [$firstReporter, $secondReporter, $author] = User::factory()->count(3)->create();
        $firstRecipe = $this->recipe($author, 'First notified recipe');
        $secondRecipe = $this->recipe($author, 'Second notified recipe');
        $this->actingAs($firstReporter)->post(route('gallery.report.store', $firstRecipe), ['reason' => 'broken']);
        $this->actingAs($secondReporter)->post(route('gallery.report.store', $secondRecipe), ['reason' => 'security']);
        $firstReport = $firstReporter->recipeReports()->sole();
        $secondReport = $secondReporter->recipeReports()->sole();

        $this->actingAs($author)->patch(route('gallery.reports.resolve-many'), [
            'reports' => [$firstReport->id],
        ]);

        $this->assertNotNull($firstReport->refresh()->resolved_at);
        $this->assertNull($secondReport->refresh()->resolved_at);
        $this->assertSame(1, $author->unreadNotifications()->count());
        $this->assertSame($secondReport->id, $author->unreadNotifications()->sole()->data['resource_id']);
    }

    public function test_resolution_and_reopen_send_private_current_state_notifications_to_reporter(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Reporter lifecycle helper');
        $this->actingAs($reporter)->post(route('gallery.report.store', $recipe), [
            'reason' => 'security',
            'details' => 'private exploit details',
        ]);
        $report = $reporter->recipeReports()->sole();

        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]), [
            'resolution_note' => 'private remediation details',
        ]);

        $resolved = $reporter->unreadNotifications()->sole();
        $this->assertSame([
            'category' => 'gallery',
            'resource_id' => $recipe->id,
            'report_id' => $report->id,
            'title' => 'Gallery report resolved',
            'message' => 'The contributor resolved your report for "Reporter lifecycle helper". A resolution note is available.',
            'status' => 'info',
        ], $resolved->data);
        $payload = json_encode($resolved->data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private exploit details', $payload);
        $this->assertStringNotContainsString('private remediation details', $payload);
        $this->assertStringNotContainsString($author->email, $payload);

        $this->actingAs($reporter)->post(route('notifications.read', $resolved->id))
            ->assertRedirect(route('gallery.report.status', $report));
        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]));
        $this->assertSame(1, $reporter->notifications()->count());

        $this->actingAs($author)->patch(route('gallery.reports.reopen', [$recipe, $report]));
        $reopened = $reporter->unreadNotifications()->sole();
        $this->assertSame('Gallery report reopened', $reopened->data['title']);
        $this->assertSame('The contributor reopened your report for "Reporter lifecycle helper".', $reopened->data['message']);
        $this->assertSame(2, $reporter->notifications()->count());
    }

    public function test_bulk_resolution_notifies_each_reporter_without_cross_account_delivery(): void
    {
        [$firstReporter, $secondReporter, $outsider, $author] = User::factory()->count(4)->create();
        $recipe = $this->recipe($author, 'Bulk reporter helper');
        $first = $firstReporter->recipeReports()->create(['recipe_id' => $recipe->id, 'reason' => 'broken']);
        $second = $secondReporter->recipeReports()->create(['recipe_id' => $recipe->id, 'reason' => 'outdated']);

        $this->actingAs($author)->patch(route('gallery.reports.resolve-many'), [
            'reports' => [$first->id, $second->id],
        ]);

        $this->assertSame('Gallery report resolved', $firstReporter->unreadNotifications()->sole()->data['title']);
        $this->assertSame($first->id, $firstReporter->unreadNotifications()->sole()->data['report_id']);
        $this->assertSame('Gallery report resolved', $secondReporter->unreadNotifications()->sole()->data['title']);
        $this->assertSame($second->id, $secondReporter->unreadNotifications()->sole()->data['report_id']);
        $this->assertSame(0, $outsider->notifications()->count());
    }

    public function test_unpublished_recipe_sends_reporter_to_the_private_status_page(): void
    {
        [$reporter, $author] = User::factory()->count(2)->create();
        $recipe = $this->recipe($author, 'Unpublished lifecycle helper');
        $report = $reporter->recipeReports()->create(['recipe_id' => $recipe->id, 'reason' => 'other']);
        $recipe->update(['is_published' => false, 'published_at' => null]);

        $this->actingAs($author)->patch(route('gallery.reports.resolve', [$recipe, $report]));

        $this->assertNotNull($report->refresh()->resolved_at);
        $notification = $reporter->unreadNotifications()->sole();
        $this->assertSame('Gallery report resolved', $notification->data['title']);
        $this->actingAs($reporter)->post(route('notifications.read', $notification))
            ->assertRedirect(route('gallery.report.status', $report));
        $this->actingAs($reporter)->get(route('gallery.report.status', $report))
            ->assertSuccessful()
            ->assertSee('This recipe is no longer published.')
            ->assertDontSee('report-notification');
    }

    private function recipe(User $author, string $name): Recipe
    {
        return $author->recipes()->create([
            'name' => $name,
            'description' => "Description for {$name}.",
            'script' => 'echo report-notification',
            'category' => 'security',
            'is_published' => true,
            'published_at' => now(),
            'gallery_revision_at' => now(),
        ]);
    }
}
