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

    public function reopened(Recipe $recipe, RecipeReport $report): void
    {
        $report->loadMissing('user:id');
        $this->notifyReporter($report, $recipe, 'reopened');
    }

    public function forget(Recipe $recipe, RecipeReport $report): void
    {
        $this->deleteContributorNotifications((int) $recipe->user_id, [$report->id]);
        $this->deleteReporterNotifications((int) $report->user_id, [$report->id]);
    }

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
