<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\RecipeReportNotifier;
use App\Support\DateRange;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipeReportsController extends Controller
{
    public function mine(Request $request): View
    {
        $filters = $this->reporterFilters($request);
        $query = $this->reportsForReporter($request, $filters);
        $reports = $this->orderedReporterReports(
            (clone $query)
                ->select(['id', 'user_id', 'recipe_id', 'reason', 'resolved_at', 'created_at', 'updated_at'])
                ->with('recipe:id,name,category,is_published,published_at'),
            $filters,
        )
            ->paginate(20)
            ->withQueryString();

        return view('scenes.gallery.my-reports', [
            'reports' => $reports,
            'unreadUpdates' => $this->unreadReportUpdates($request, $reports->getCollection()->modelKeys()),
            'filters' => $filters,
            'metrics' => [
                'matching' => (clone $query)->count(),
                'open' => (clone $query)->whereNull('resolved_at')->count(),
                'resolved' => (clone $query)->whereNotNull('resolved_at')->count(),
                'unpublished' => (clone $query)
                    ->whereHas('recipe', fn ($recipe) => $recipe->where(fn ($recipe) => $recipe
                        ->where('is_published', false)
                        ->orWhereNull('published_at')))
                    ->count(),
                'unread_updates' => $this->unreadReportUpdateQuery($request)->count(),
            ],
        ]);
    }

    public function reviewUpdates(Request $request): RedirectResponse
    {
        $reviewed = $this->unreadReportUpdateQuery($request)->update(['read_at' => now()]);

        return back()->with('status', $reviewed > 0
            ? trans_choice(':count report update was marked as reviewed.|:count report updates were marked as reviewed.', $reviewed, ['count' => $reviewed])
            : __('There are no unread report updates.'));
    }

    public function exportMine(Request $request): StreamedResponse
    {
        $filters = $this->reporterFilters($request);
        $filename = 'lessbuild-my-community-reports-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
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
            ], ',', '"', '');

            $this->orderedReporterReports(
                $this->reportsForReporter($request, $filters)
                    ->select([
                        'id',
                        'user_id',
                        'recipe_id',
                        'reason',
                        'details',
                        'resolved_at',
                        'resolution_note',
                        'created_at',
                        'updated_at',
                    ])
                    ->with('recipe:id,name,category,is_published,published_at'),
                $filters,
            )
                ->lazy(250)
                ->each(function (RecipeReport $report) use ($output): void {
                    fputcsv($output, [
                        $report->id,
                        $report->recipe_id,
                        $this->csvCell($report->recipe->name),
                        $this->csvCell($report->recipe->category),
                        $report->recipe->is_published && $report->recipe->published_at !== null ? 'published' : 'unpublished',
                        $this->csvCell($report->reason),
                        $report->resolved_at === null ? 'needs_review' : 'resolved',
                        $this->csvCell($report->details),
                        $this->csvCell($report->resolution_note),
                        $report->created_at?->toIso8601String(),
                        $report->resolved_at?->toIso8601String(),
                        $report->updated_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function status(Request $request, string $report): View
    {
        $report = $request->user()->recipeReports()
            ->select([
                'id',
                'user_id',
                'recipe_id',
                'reason',
                'details',
                'resolved_at',
                'resolution_note',
                'created_at',
                'updated_at',
            ])
            ->with('recipe:id,user_id,name,category,is_published,published_at')
            ->findOrFail($report);

        return view('scenes.gallery.report-status', [
            'report' => $report,
            'unreadUpdate' => $this->unreadReportUpdates($request, [$report->id])->get($report->id),
        ]);
    }

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->reportsForContributor($request, $filters);

        return view('scenes.gallery.reports', [
            'reports' => $this->orderedReports(
                (clone $query)
                    ->select(['id', 'recipe_id', 'reason', 'details', 'resolved_at', 'resolution_note', 'created_at', 'updated_at'])
                    ->with('recipe:id,user_id,name,category,is_published,published_at'),
                $filters,
            )
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
            'reasons' => RecipeReport::REASONS,
            'metrics' => [
                'matching' => (clone $query)->count(),
                'unresolved' => (clone $query)->whereNull('resolved_at')->count(),
                'resolved' => (clone $query)->whereNotNull('resolved_at')->count(),
                'recipes' => (clone $query)->distinct()->count('recipe_id'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'lessbuild-community-feedback-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
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
            ], ',', '"', '');

            $this->orderedReports(
                $this->reportsForContributor($request, $filters)
                    ->select(['id', 'recipe_id', 'reason', 'details', 'resolved_at', 'resolution_note', 'created_at', 'updated_at'])
                    ->with('recipe:id,name,category'),
                $filters,
            )
                ->lazy(250)
                ->each(function (RecipeReport $report) use ($output): void {
                    fputcsv($output, [
                        $report->id,
                        $report->recipe_id,
                        $this->csvCell($report->recipe->name),
                        $this->csvCell($report->recipe->category),
                        $this->csvCell($report->reason),
                        $report->resolved_at === null ? 'needs_review' : 'resolved',
                        $this->csvCell($report->details),
                        $report->created_at?->toIso8601String(),
                        $report->resolved_at?->toIso8601String(),
                        $this->csvCell($report->resolution_note),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Recipe $recipe, ActivityRecorder $activity, RecipeReportNotifier $notifications): RedirectResponse
    {
        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);
        abort_if((int) $recipe->user_id === (int) $request->user()->id, 403);

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(RecipeReport::REASONS)],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['details'] = filled($data['details'] ?? null)
            ? str($data['details'])->trim()->toString()
            : null;
        $data['resolved_at'] = null;
        $data['resolution_note'] = null;

        DB::transaction(function () use ($activity, $data, $notifications, $recipe, $request): void {
            $lockedRecipe = $this->lockedRecipe($recipe->id);
            abort_unless($lockedRecipe->is_published && $lockedRecipe->published_at !== null, 404);
            abort_if((int) $lockedRecipe->user_id === (int) $request->user()->id, 403);

            $report = $request->user()->recipeReports()
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->first();
            if ($report === null) {
                $report = $request->user()->recipeReports()->create([
                    'recipe_id' => $lockedRecipe->id,
                    ...$data,
                ]);
            } else {
                $report->fill($data)->save();
            }

            $notifications->open($lockedRecipe, $report);
            $activity->record(
                $lockedRecipe,
                $request->user()->id,
                'recipe',
                $report->wasRecentlyCreated
                    ? "Gallery recipe \"{$lockedRecipe->name}\" was reported as {$report->reason}."
                    : "Gallery recipe \"{$lockedRecipe->name}\" report was updated to {$report->reason}.",
            );
        });

        return back()->with('status', __('Your private gallery report was saved.'));
    }

    public function resolve(Request $request, Recipe $recipe, RecipeReport $report, ActivityRecorder $activity, RecipeReportNotifier $notifications): RedirectResponse
    {
        $this->authorizeContributorReport($request, $recipe, $report);
        $resolutionNote = $this->resolutionNote($request);

        DB::transaction(function () use ($activity, $notifications, $recipe, $report, $request, $resolutionNote): void {
            $lockedRecipe = $this->lockedRecipe($recipe->id);
            $lockedReport = RecipeReport::query()
                ->whereKey($report->id)
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorizeContributorReport($request, $lockedRecipe, $lockedReport);
            $wasResolved = $lockedReport->resolved_at === null;

            if ($wasResolved) {
                $lockedReport->update([
                    'resolved_at' => now(),
                    'resolution_note' => $resolutionNote,
                ]);
            }
            $notifications->resolve($request->user(), [$lockedReport->id]);
            if ($wasResolved) {
                $notifications->resolved([$lockedReport->id]);
                $activity->record(
                    $lockedRecipe,
                    $request->user()->id,
                    'recipe',
                    "A community report for gallery recipe \"{$lockedRecipe->name}\" was resolved.",
                );
            }
        });

        return back()->with('status', __('The community report was marked as resolved.'));
    }

    public function updateResolutionNote(Request $request, Recipe $recipe, RecipeReport $report, ActivityRecorder $activity, RecipeReportNotifier $notifications): RedirectResponse
    {
        $this->authorizeContributorReport($request, $recipe, $report);

        $resolutionNote = $this->resolutionNote($request);
        $updated = DB::transaction(function () use ($activity, $notifications, $recipe, $report, $request, $resolutionNote): bool {
            $lockedRecipe = $this->lockedRecipe($recipe->id);
            $lockedReport = RecipeReport::query()
                ->whereKey($report->id)
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorizeContributorReport($request, $lockedRecipe, $lockedReport);
            abort_if($lockedReport->resolved_at === null, 409);
            if ($lockedReport->resolution_note === $resolutionNote) {
                return false;
            }

            $lockedReport->update(['resolution_note' => $resolutionNote]);
            $notifications->resolved([$lockedReport->id]);
            $activity->record(
                $lockedRecipe,
                $request->user()->id,
                'recipe',
                "A community report resolution note for gallery recipe \"{$lockedRecipe->name}\" was updated.",
            );

            return true;
        });

        if (! $updated) {
            return back()->with('status', __('The resolution note is unchanged.'));
        }

        return back()->with('status', $resolutionNote === null
            ? __('The resolution note was cleared.')
            : __('The resolution note was updated.'));
    }

    public function resolveMany(Request $request, ActivityRecorder $activity, RecipeReportNotifier $notifications): RedirectResponse
    {
        $data = $request->validateWithBag('bulkResolve', [
            'reports' => ['required', 'array', 'min:1', 'max:20'],
            'reports.*' => ['required', 'integer', 'distinct:strict'],
        ]);
        $reportIds = collect($data['reports'])->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $resolvedCount = DB::transaction(function () use ($activity, $notifications, $request, $reportIds): int {
            $reports = RecipeReport::query()
                ->whereIn('id', $reportIds)
                ->whereHas('recipe', fn ($query) => $query->where('user_id', $request->user()->id))
                ->select(['id', 'recipe_id', 'resolved_at'])
                ->with('recipe:id,user_id,name')
                ->lockForUpdate()
                ->get();

            abort_unless($reports->count() === count($reportIds), 404);

            $unresolved = $reports->whereNull('resolved_at');
            if ($unresolved->isEmpty()) {
                $notifications->resolve($request->user(), $reportIds);

                return 0;
            }

            RecipeReport::query()
                ->whereKey($unresolved->modelKeys())
                ->update([
                    'resolved_at' => now(),
                    'resolution_note' => null,
                    'updated_at' => now(),
                ]);

            $notifications->resolve($request->user(), $reportIds);
            $notifications->resolved($unresolved->modelKeys());

            $unresolved->groupBy('recipe_id')->each(function ($reports) use ($activity, $request): void {
                $recipe = $reports->first()->recipe;
                $activity->record(
                    $recipe,
                    $request->user()->id,
                    'recipe',
                    trans_choice(
                        ':count community report for gallery recipe ":recipe" was resolved.|:count community reports for gallery recipe ":recipe" were resolved.',
                        $reports->count(),
                        ['count' => $reports->count(), 'recipe' => $recipe->name],
                    ),
                );
            });

            return $unresolved->count();
        });

        return back()->with('status', $resolvedCount > 0
            ? trans_choice(':count community report was marked as resolved.|:count community reports were marked as resolved.', $resolvedCount, ['count' => $resolvedCount])
            : __('The selected community reports were already resolved.'));
    }

    public function reopen(Request $request, Recipe $recipe, RecipeReport $report, ActivityRecorder $activity, RecipeReportNotifier $notifications): RedirectResponse
    {
        $this->authorizeContributorReport($request, $recipe, $report);

        DB::transaction(function () use ($activity, $notifications, $recipe, $report, $request): void {
            $lockedRecipe = $this->lockedRecipe($recipe->id);
            $lockedReport = RecipeReport::query()
                ->whereKey($report->id)
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorizeContributorReport($request, $lockedRecipe, $lockedReport);
            if ($lockedReport->resolved_at === null) {
                return;
            }

            $lockedReport->update([
                'resolved_at' => null,
                'resolution_note' => null,
            ]);
            $notifications->open($lockedRecipe, $lockedReport);
            $notifications->reopened($lockedRecipe, $lockedReport);
            $activity->record(
                $lockedRecipe,
                $request->user()->id,
                'recipe',
                "A community report for gallery recipe \"{$lockedRecipe->name}\" was reopened.",
            );
        });

        return back()->with('status', __('The community report was reopened.'));
    }

    public function reopenMany(Request $request, ActivityRecorder $activity, RecipeReportNotifier $notifications): RedirectResponse
    {
        $data = $request->validateWithBag('bulkReopen', [
            'reports' => ['required', 'array', 'min:1', 'max:20'],
            'reports.*' => ['required', 'integer', 'distinct:strict'],
        ]);
        $reportIds = collect($data['reports'])->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $reopenedCount = DB::transaction(function () use ($activity, $notifications, $request, $reportIds): int {
            $reports = RecipeReport::query()
                ->whereIn('id', $reportIds)
                ->whereHas('recipe', fn ($query) => $query->where('user_id', $request->user()->id))
                ->select(['id', 'recipe_id', 'resolved_at'])
                ->with('recipe:id,user_id,name')
                ->lockForUpdate()
                ->get();

            abort_unless($reports->count() === count($reportIds), 404);

            $resolved = $reports->whereNotNull('resolved_at');
            if ($resolved->isEmpty()) {
                return 0;
            }

            RecipeReport::query()
                ->whereKey($resolved->modelKeys())
                ->update([
                    'resolved_at' => null,
                    'resolution_note' => null,
                    'updated_at' => now(),
                ]);

            RecipeReport::query()
                ->whereKey($resolved->modelKeys())
                ->select(['id', 'user_id', 'recipe_id'])
                ->with(['recipe:id,user_id,name'])
                ->get()
                ->each(function (RecipeReport $report) use ($notifications): void {
                    $notifications->open($report->recipe, $report);
                    $notifications->reopened($report->recipe, $report);
                });

            $resolved->groupBy('recipe_id')->each(function ($reports) use ($activity, $request): void {
                $recipe = $reports->first()->recipe;
                $activity->record(
                    $recipe,
                    $request->user()->id,
                    'recipe',
                    trans_choice(
                        ':count community report for gallery recipe ":recipe" was reopened.|:count community reports for gallery recipe ":recipe" were reopened.',
                        $reports->count(),
                        ['count' => $reports->count(), 'recipe' => $recipe->name],
                    ),
                );
            });

            return $resolved->count();
        });

        return back()->with('status', $reopenedCount > 0
            ? trans_choice(':count community report was reopened.|:count community reports were reopened.', $reopenedCount, ['count' => $reopenedCount])
            : __('The selected community reports were already open.'));
    }

    public function destroy(Request $request, Recipe $recipe, ActivityRecorder $activity, RecipeReportNotifier $notifications): RedirectResponse
    {
        DB::transaction(function () use ($activity, $notifications, $recipe, $request): void {
            $lockedRecipe = $this->lockedRecipe($recipe->id);
            $lockedReport = $request->user()->recipeReports()
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->firstOrFail();
            $notifications->forget($lockedRecipe, $lockedReport);
            $lockedReport->delete();
            $activity->record(
                $lockedRecipe,
                $request->user()->id,
                'recipe',
                "Gallery recipe \"{$lockedRecipe->name}\" report was withdrawn.",
            );
        });

        return back()->with('status', __('Your gallery report was withdrawn.'));
    }

    /** @return array{search: ?string, status: string, reason: ?string, date_from: ?string, date_to: ?string, age: ?string, sort: string, recipe: ?int, report: ?int} */
    private function filters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $status = $request->string('status')->toString();
        $reason = $request->string('reason')->toString();
        $age = $request->string('age')->toString();
        $sort = $request->string('sort')->toString();
        $report = filter_var($request->query('report'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $recipe = filter_var($request->query('recipe'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        [$dateFrom, $dateTo] = DateRange::normalize(
            $request->string('date_from')->toString(),
            $request->string('date_to')->toString(),
        );

        return [
            'search' => $search !== '' ? $search : null,
            'status' => in_array($status, ['all', 'unresolved', 'resolved'], true) ? $status : 'unresolved',
            'reason' => in_array($reason, RecipeReport::REASONS, true) ? $reason : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'age' => in_array($age, ['24h', '7d', '30d'], true) ? $age : null,
            'sort' => in_array($sort, ['newest', 'oldest', 'updated', 'priority'], true) ? $sort : 'newest',
            'recipe' => $recipe ?: null,
            'report' => $report ?: null,
        ];
    }

    /** @param array{search: ?string, status: string, reason: ?string, date_from: ?string, date_to: ?string, age: ?string, sort: string, recipe: ?int, report: ?int} $filters */
    private function reportsForContributor(Request $request, array $filters): Builder
    {
        return RecipeReport::query()
            ->whereHas('recipe', fn ($query) => $query
                ->where('user_id', $request->user()->id)
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

    /** @param array{search: ?string, status: string, reason: ?string, date_from: ?string, date_to: ?string, age: ?string, sort: string, recipe: ?int, report: ?int} $filters */
    private function orderedReports(Builder $query, array $filters): Builder
    {
        $query->orderByRaw('resolved_at IS NULL DESC');

        return match ($filters['sort']) {
            'oldest' => $query->oldest('created_at')->oldest('id'),
            'updated' => $query->latest('updated_at')->latest('id'),
            'priority' => $this->orderReportsByPriority($query),
            default => $query->latest('created_at')->latest('id'),
        };
    }

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

    private function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function resolutionNote(Request $request): ?string
    {
        $data = $request->validate([
            'resolution_note' => ['nullable', 'string', 'max:1000'],
        ]);

        return filled($data['resolution_note'] ?? null)
            ? str($data['resolution_note'])->trim()->toString()
            : null;
    }

    private function authorizeContributorReport(Request $request, Recipe $recipe, RecipeReport $report): void
    {
        abort_unless(
            (int) $recipe->user_id === (int) $request->user()->id
                && (int) $report->recipe_id === (int) $recipe->id,
            404,
        );
    }

    private function csvCell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\0", '', $value);

        return preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }

    /** @return array{search: ?string, status: string, availability: string, updates: string, reason: ?string, sort: string} */
    private function reporterFilters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $status = $request->string('status')->toString();
        $availability = $request->string('availability')->toString();
        $updates = $request->string('updates')->toString();
        $reason = $request->string('reason')->toString();
        $sort = $request->string('sort')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'status' => in_array($status, ['all', 'open', 'resolved'], true) ? $status : 'all',
            'availability' => in_array($availability, ['all', 'published', 'unpublished'], true) ? $availability : 'all',
            'updates' => in_array($updates, ['all', 'unread', 'reviewed'], true) ? $updates : 'all',
            'reason' => in_array($reason, RecipeReport::REASONS, true) ? $reason : null,
            'sort' => in_array($sort, ['newest', 'oldest', 'updated'], true) ? $sort : 'newest',
        ];
    }

    /** @param array{search: ?string, status: string, availability: string, updates: string, reason: ?string, sort: string} $filters */
    private function reportsForReporter(Request $request, array $filters): HasMany
    {
        return $request->user()->recipeReports()
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
                fn (QueryBuilder $notifications) => $this->unreadReportUpdateExists($notifications, (int) $request->user()->id),
            ))
            ->when($filters['updates'] === 'reviewed', fn ($reports) => $reports->whereNotExists(
                fn (QueryBuilder $notifications) => $this->unreadReportUpdateExists($notifications, (int) $request->user()->id),
            ))
            ->when($filters['reason'], fn ($reports, string $reason) => $reports->where('reason', $reason));
    }

    /** @param array{search: ?string, status: string, availability: string, updates: string, reason: ?string, sort: string} $filters */
    private function orderedReporterReports(HasMany $query, array $filters): HasMany
    {
        return match ($filters['sort']) {
            'oldest' => $query->oldest('created_at')->oldest('id'),
            'updated' => $query->latest('updated_at')->latest('id'),
            default => $query->latest('created_at')->latest('id'),
        };
    }

    private function lockedRecipe(int $recipeId): Recipe
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Recipe::query()->whereKey($recipeId)->update(['id' => DB::raw('id')]);
        }

        return Recipe::query()->whereKey($recipeId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<int>  $reportIds
     * @return Collection<int, DatabaseNotification>
     */
    private function unreadReportUpdates(Request $request, array $reportIds): Collection
    {
        if ($reportIds === []) {
            return collect();
        }

        return $this->unreadReportUpdateQuery($request)
            ->whereIn('data->report_id', $reportIds)
            ->select(['id', 'data', 'created_at'])
            ->latest('created_at')
            ->get()
            ->keyBy(fn ($notification): int => (int) $notification->data['report_id']);
    }

    private function unreadReportUpdateQuery(Request $request): MorphMany
    {
        return $request->user()->unreadNotifications()
            ->where('data->category', 'gallery')
            ->whereNotNull('data->report_id');
    }

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
