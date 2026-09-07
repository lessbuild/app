<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Models\User;
use App\Notifications\RecipeReportNotification;
use App\Notifications\RecipeReportStatusNotification;
use Illuminate\Notifications\DatabaseNotification;

class RecipeReportNotifier
{
    /**
     * Notify a recipe contributor once while a report notification remains unread.
     *
     * @param  Recipe  $recipe  The recipe whose contributor receives the report.
     * @param  RecipeReport  $report  The report identity used to deduplicate unread notifications.
     * @return void No value; creates a contributor notification only when none is already open.
     */
    public function open(Recipe $recipe, RecipeReport $report): void
    {
        $contributor = $recipe->user()->select('users.id')->firstOrFail();
        $alreadyOpen = $contributor->unreadNotifications()
            ->where('data->category', 'recipe')
            ->where('data->resource_id', $report->id)
            ->exists();

        if (! $alreadyOpen) {
            $contributor->notify(new RecipeReportNotification($recipe, $report));
        }
    }

    /** @param list<int> $reportIds */
    public function resolve(User $contributor, array $reportIds): int
    {
        if ($reportIds === []) {
            return 0;
        }

        return $contributor->unreadNotifications()
            ->where('data->category', 'recipe')
            ->whereIn('data->resource_id', $reportIds)
            ->update(['read_at' => now()]);
    }

    /** @param list<int> $reportIds */
    public function resolved(array $reportIds): void
    {
        RecipeReport::query()
            ->whereKey($reportIds)
            ->select(['id', 'user_id', 'recipe_id', 'resolution_note'])
            ->with(['user:id', 'recipe:id,name'])
            ->get()
            ->each(fn (RecipeReport $report) => $this->notifyReporter($report, $report->recipe, 'resolved'));
    }

    /**
     * Notify the report author that a recipe report has been reopened.
     *
     * @param  Recipe  $recipe  The recipe associated with the reopened report.
     * @param  RecipeReport  $report  The report whose author should receive the status update.
     * @return void No value; marks previous status notifications read before notifying.
     */
    public function reopened(Recipe $recipe, RecipeReport $report): void
    {
        $report->loadMissing('user:id');
        $this->notifyReporter($report, $recipe, 'reopened');
    }

    /**
     * Delete contributor and reporter notifications for one recipe report.
     *
     * @param  Recipe  $recipe  The recipe identifying its contributor.
     * @param  RecipeReport  $report  The report identifying its author and notification references.
     * @return void No value; removes matching notifications for both participants.
     */
    public function forget(Recipe $recipe, RecipeReport $report): void
    {
        $this->deleteContributorNotifications((int) $recipe->user_id, [$report->id]);
        $this->deleteReporterNotifications((int) $report->user_id, [$report->id]);
    }

    /**
     * Delete all report-related notifications for a recipe and its report authors.
     *
     * @param  Recipe  $recipe  The recipe whose report IDs and authors define the deletion scope.
     * @return void No value; returns immediately when the recipe has no reports.
     */
    public function forgetRecipe(Recipe $recipe): void
    {
        $reports = $recipe->reports()->select(['id', 'user_id'])->get();
        if ($reports->isEmpty()) {
            return;
        }

        $this->deleteContributorNotifications((int) $recipe->user_id, $reports->modelKeys());
        $reports->groupBy('user_id')->each(function ($reports, int|string $reporterId): void {
            $this->deleteReporterNotifications((int) $reporterId, $reports->modelKeys());
        });
    }

    /**
     * Replace unread report-status notifications with the requested status update.
     *
     * @param  RecipeReport  $report  The report whose author receives the notification.
     * @param  Recipe  $recipe  The recipe shown in the report notification.
     * @param  string  $state  The report lifecycle state to communicate.
     * @return void No value; marks earlier status notifications read and sends the new notification.
     */
    private function notifyReporter(RecipeReport $report, Recipe $recipe, string $state): void
    {
        $report->user->unreadNotifications()
            ->where('data->category', 'gallery')
            ->where('data->report_id', $report->id)
            ->update(['read_at' => now()]);

        $report->user->notify(new RecipeReportStatusNotification($recipe, $report, $state));
    }

    /** @param list<int> $reportIds */
    private function deleteContributorNotifications(int $contributorId, array $reportIds): void
    {
        DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $contributorId)
            ->where('data->category', 'recipe')
            ->whereIn('data->resource_id', $reportIds)
            ->delete();
    }

    /** @param list<int> $reportIds */
    private function deleteReporterNotifications(int $reporterId, array $reportIds): void
    {
        DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $reporterId)
            ->where('data->category', 'gallery')
            ->whereIn('data->report_id', $reportIds)
            ->delete();
    }
}
