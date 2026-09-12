<?php

namespace App\Services;

use App\Models\RecipeReport;
use App\Models\User;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

class RecipeReportQuery
{
    /**
     * Build the contributor-scoped report inbox query with its search, status, age and focus filters.
     *
     * @param  array{search: ?string, status: string, reason: ?string, date_from: ?string, date_to: ?string, age: ?string, sort: string, recipe: ?int, report: ?int}  $filters
     * @return Builder<RecipeReport> The report query constrained to recipes contributed by the user.
     */
    public function forContributor(User $user, array $filters): Builder
    {
        return RecipeReport::query()
            ->whereHas('recipe', fn ($query) => $query
                ->where('user_id', $user->id)
                ->when($filters['search'], fn ($query, string $search) => $query
                    ->whereRaw("name LIKE ? ESCAPE '!'", [SqlLike::contains($search)])))
            ->when($filters['status'] === 'unresolved', fn ($query) => $query->whereNull('resolved_at'))
            ->when($filters['status'] === 'resolved', fn ($query) => $query->whereNotNull('resolved_at'))
            ->when($filters['reason'], fn ($query, string $reason) => $query->where('reason', $reason))
            ->when($filters['date_from'], fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['age'], fn ($query, string $age) => $query->where('created_at', '<=', match ($age) {
                '24h' => now()->subDay(),
                '7d' => now()->subDays(7),
                '30d' => now()->subDays(30),
            }))
            ->when($filters['recipe'], fn ($query, int $recipeId) => $query->where('recipe_id', $recipeId))
            ->when($filters['report'], fn ($query, int $reportId) => $query->whereKey($reportId));
    }

    /**
     * Build the reporter-scoped report history query with recipe availability and unread-update filters.
     *
     * @param  array{search: ?string, status: string, availability: string, updates: string, reason: ?string, sort: string}  $filters
     * @return HasMany<RecipeReport, User> The report relationship constrained to reports submitted by the user.
     */
    public function forReporter(User $user, array $filters): HasMany
    {
        return $user->recipeReports()
            ->whereHas('recipe', fn ($recipe) => $recipe
                ->when($filters['search'], fn ($recipe, string $search) => $recipe
                    ->whereRaw("name LIKE ? ESCAPE '!'", [SqlLike::contains($search)]))
                ->when($filters['availability'] === 'published', fn ($recipe) => $recipe
                    ->where('is_published', true)
                    ->whereNotNull('published_at'))
                ->when($filters['availability'] === 'unpublished', fn ($recipe) => $recipe
                    ->where(fn ($recipe) => $recipe
                        ->where('is_published', false)
                        ->orWhereNull('published_at'))))
            ->when($filters['status'] === 'open', fn ($reports) => $reports->whereNull('resolved_at'))
            ->when($filters['status'] === 'resolved', fn ($reports) => $reports->whereNotNull('resolved_at'))
            ->when($filters['updates'] === 'unread', fn ($reports) => $reports->whereExists(
                fn (QueryBuilder $notifications) => $this->unreadReportUpdateExists($notifications, (int) $user->id),
            ))
            ->when($filters['updates'] === 'reviewed', fn ($reports) => $reports->whereNotExists(
                fn (QueryBuilder $notifications) => $this->unreadReportUpdateExists($notifications, (int) $user->id),
            ))
            ->when($filters['reason'], fn ($reports, string $reason) => $reports->where('reason', $reason));
    }

    /**
     * Apply contributor inbox ordering, keeping unresolved reports ahead of resolved reports.
     *
     * @param  array{search: ?string, status: string, reason: ?string, date_from: ?string, date_to: ?string, age: ?string, sort: string, recipe: ?int, report: ?int}  $filters
     * @return Builder<RecipeReport> The same report query with deterministic ordering.
     */
    public function ordered(Builder $query, array $filters): Builder
    {
        $query->orderByRaw('resolved_at IS NULL DESC');

        return match ($filters['sort']) {
            'oldest' => $query->oldest('created_at')->oldest('id'),
            'updated' => $query->latest('updated_at')->latest('id'),
            'priority' => $this->orderReportsByPriority($query),
            default => $query->latest('created_at')->latest('id'),
        };
    }

    /**
     * Apply reporter history ordering without changing the relationship's scope.
     *
     * @param  array{search: ?string, status: string, availability: string, updates: string, reason: ?string, sort: string}  $filters
     * @return HasMany<RecipeReport, User> The same report relationship with deterministic ordering.
     */
    public function orderedReporter(HasMany $query, array $filters): HasMany
    {
        return match ($filters['sort']) {
            'oldest' => $query->oldest('created_at')->oldest('id'),
            'updated' => $query->latest('updated_at')->latest('id'),
            default => $query->latest('created_at')->latest('id'),
        };
    }

    /**
     * Load the user's unread gallery report updates for a bounded set of report IDs.
     *
     * @param  list<int>  $reportIds
     * @return Collection<int, DatabaseNotification> The newest notification per report ID.
     */
    public function unread(User $user, array $reportIds): Collection
    {
        if ($reportIds === []) {
            return collect();
        }

        return $this->unreadUpdates($user)
            ->whereIn('data->report_id', $reportIds)
            ->select(['id', 'data', 'created_at'])
            ->latest('created_at')
            ->get()
            ->keyBy(fn ($notification): int => (int) $notification->data['report_id']);
    }

    /**
     * Build the user's unread gallery report-update notification query.
     *
     * @return MorphMany<DatabaseNotification, User> The unread gallery notifications with a report reference.
     */
    public function unreadUpdates(User $user): MorphMany
    {
        return $user->unreadNotifications()
            ->where('data->category', 'gallery')
            ->whereNotNull('data->report_id');
    }

    /**
     * Order reports by the finite reason priority configured on the model.
     *
     * @param  Builder<RecipeReport>  $query  The already-scoped report query.
     * @return Builder<RecipeReport> The same builder with deterministic priority ordering.
     */
    private function orderReportsByPriority(Builder $query): Builder
    {
        $cases = collect(RecipeReport::REASONS)
            ->map(fn (string $reason, int $priority): string => "WHEN ? THEN {$priority}")
            ->implode(' ');

        return $query
            ->orderByRaw(
                "CASE recipe_reports.reason {$cases} ELSE ".count(RecipeReport::REASONS).' END',
                RecipeReport::REASONS,
            )
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * Configure an EXISTS subquery matching unread gallery updates for a user and outer report row.
     */
    private function unreadReportUpdateExists(QueryBuilder $notifications, int $userId): void
    {
        $notifications
            ->selectRaw('1')
            ->from('notifications')
            ->where('notifications.notifiable_type', User::class)
            ->where('notifications.notifiable_id', $userId)
            ->whereNull('notifications.read_at')
            ->where('notifications.data->category', 'gallery')
            ->whereColumn('notifications.data->report_id', 'recipe_reports.id');
    }
}
