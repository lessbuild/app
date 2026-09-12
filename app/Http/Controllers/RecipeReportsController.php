<?php

namespace App\Http\Controllers;

use App\Actions\Recipe\ResolveRecipeReportAction;
use App\Actions\Recipe\SubmitRecipeReportAction;
use App\Actions\Recipe\UpdateRecipeReportResolutionNoteAction;
use App\Http\Requests\RecipeReportResolutionRequest;
use App\Http\Requests\ReopenRecipeReportsRequest;
use App\Http\Requests\ResolveRecipeReportsRequest;
use App\Http\Requests\StoreRecipeReportRequest;
use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Services\ActivityRecorder;
use App\Services\RecipeReportHistoryExporter;
use App\Services\RecipeReportInboxExporter;
use App\Services\RecipeReportNotifier;
use App\Services\RecipeReportQuery;
use App\Support\DateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipeReportsController extends Controller
{
    public function __construct(
        private readonly RecipeReportHistoryExporter $historyExporter,
        private readonly RecipeReportInboxExporter $inboxExporter,
        private readonly RecipeReportQuery $reportQuery,
    ) {}

    /**
     * Render the request user's filtered recipe reports with pagination, availability counts, and unread report updates.
     */
    public function mine(Request $request): View
    {
        $filters = $this->reporterFilters($request);
        $query = $this->reportQuery->forReporter($request->user(), $filters);
        $reports = $this->reportQuery->orderedReporter(
            (clone $query)
                ->select(['id', 'user_id', 'recipe_id', 'reason', 'resolved_at', 'created_at', 'updated_at'])
                ->with('recipe:id,name,category,is_published,published_at'),
            $filters,
        )
            ->paginate(20)
            ->withQueryString();

        return view('scenes.gallery.my-reports', [
            'reports' => $reports,
            'unreadUpdates' => $this->reportQuery->unread($request->user(), $reports->getCollection()->modelKeys()),
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
                'unread_updates' => $this->reportQuery->unreadUpdates($request->user())->count(),
            ],
        ]);
    }

    /**
     * Mark the request user's unread gallery report notifications reviewed and redirect with the affected count.
     */
    public function reviewUpdates(Request $request): RedirectResponse
    {
        $reviewed = $this->reportQuery->unreadUpdates($request->user())->update(['read_at' => now()]);

        return back()->with('status', $reviewed > 0
            ? trans_choice(':count report update was marked as reviewed.|:count report updates were marked as reviewed.', $reviewed, ['count' => $reviewed])
            : __('There are no unread report updates.'));
    }

    /**
     * Stream the reporter's filtered submissions, recipe availability, and resolution notes as private CSV.
     */
    public function exportMine(Request $request): StreamedResponse
    {
        $filters = $this->reporterFilters($request);

        return $this->historyExporter->stream($request->user(), $filters);
    }

    /**
     * @param  Request  $request  The authenticated reporter's request.
     * @param  RecipeReport  $report  The implicitly bound report, including unpublished recipe history.
     * @return View The report status after verifying ownership.
     */
    public function status(Request $request, RecipeReport $report): View
    {
        abort_unless((int) $report->user_id === (int) $request->user()->id, 404);
        $report->load('recipe:id,user_id,name,category,is_published,published_at');

        return view('scenes.gallery.report-status', [
            'report' => $report,
            'unreadUpdate' => $this->reportQuery->unread($request->user(), [$report->id])->get($report->id),
        ]);
    }

    /**
     * Render filtered reports about the request user's contributed recipes with review and recipe counts.
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->reportQuery->forContributor($request->user(), $filters);

        return view('scenes.gallery.reports', [
            'reports' => $this->reportQuery->ordered(
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

    /**
     * Stream filtered community reports about the request user's contributed recipes as private, spreadsheet-safe CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        return $this->inboxExporter->stream($request->user(), $filters);
    }

    /**
     * Validate a report reason and optional details for another contributor's published recipe, then create or reopen the user's report.
     *
     * @return RedirectResponse A private-report acknowledgement after locked persistence, notification, and activity recording.
     */
    public function store(StoreRecipeReportRequest $request, Recipe $recipe, SubmitRecipeReportAction $submitReport): RedirectResponse
    {
        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);
        abort_if((int) $recipe->user_id === (int) $request->user()->id, 403);

        $data = $request->validated();
        $data['details'] = $request->details();
        $submitReport->handle($recipe, $request->user(), $data);

        return back()->with('status', __('Your private gallery report was saved.'));
    }

    /**
     * Authorize the contributor and recipe/report relationship, validate a resolution note, and resolve the locked report.
     *
     * @return RedirectResponse An acknowledgement; repeated resolution does not replace the existing note.
     */
    public function resolve(RecipeReportResolutionRequest $request, Recipe $recipe, RecipeReport $report, ResolveRecipeReportAction $resolveReport): RedirectResponse
    {
        $this->authorizeContributorReport($request, $recipe, $report);
        $resolutionNote = $request->resolutionNote();

        $resolveReport->handle($recipe, $report, $request->user(), $resolutionNote);

        return back()->with('status', __('The community report was marked as resolved.'));
    }

    /**
     * Authorize a resolved contributor report and validate a replacement note under the recipe/report locks.
     *
     * @return RedirectResponse The changed or unchanged note result; an unresolved report yields HTTP 409.
     */
    public function updateResolutionNote(RecipeReportResolutionRequest $request, Recipe $recipe, RecipeReport $report, UpdateRecipeReportResolutionNoteAction $updateResolutionNote): RedirectResponse
    {
        $this->authorizeContributorReport($request, $recipe, $report);

        $resolutionNote = $request->resolutionNote();
        $updated = $updateResolutionNote->handle($recipe, $report, $request->user(), $resolutionNote);

        if (! $updated) {
            return back()->with('status', __('The resolution note is unchanged.'));
        }

        return back()->with('status', $resolutionNote === null
            ? __('The resolution note was cleared.')
            : __('The resolution note was updated.'));
    }

    /**
     * Validate at most 20 distinct report IDs and require every report to belong to the contributor's recipes.
     *
     * @return RedirectResponse The count newly resolved after atomic updates and report notifications.
     */
    public function resolveMany(ResolveRecipeReportsRequest $request, ActivityRecorder $activity, RecipeReportNotifier $notifications): RedirectResponse
    {
        $reportIds = collect($request->validated('reports'))->map(fn ($id): int => (int) $id)->sort()->values()->all();

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

    /**
     * Authorize the contributor and recipe/report relationship, reopen a resolved report under locks, and redirect back.
     */
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

    /**
     * Validate at most 20 distinct contributor report IDs and atomically reopen those currently resolved.
     *
     * @return RedirectResponse The reopened count; any missing or foreign report aborts the operation with 404.
     */
    public function reopenMany(ReopenRecipeReportsRequest $request, ActivityRecorder $activity, RecipeReportNotifier $notifications): RedirectResponse
    {
        $reportIds = collect($request->validated('reports'))->map(fn ($id): int => (int) $id)->sort()->values()->all();

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

    /**
     * Withdraw the request user's report for the bound recipe under a lock and remove its notifications before redirecting back.
     */
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

    /**
     * Return an unchanged valid Y-m-d calendar date, or null for malformed or overflowing input.
     */
    private function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Return 404 unless the request user contributed the recipe and the report belongs to that recipe.
     */
    private function authorizeContributorReport(Request $request, Recipe $recipe, RecipeReport $report): void
    {
        abort_unless(
            (int) $recipe->user_id === (int) $request->user()->id
                && (int) $report->recipe_id === (int) $recipe->id,
            404,
        );
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

    /**
     * Load a recipe by ID under a transaction lock, taking a SQLite write lock when needed; missing IDs return 404.
     */
    private function lockedRecipe(int $recipeId): Recipe
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Recipe::query()->whereKey($recipeId)->update(['id' => DB::raw('id')]);
        }

        return Recipe::query()->whereKey($recipeId)->lockForUpdate()->firstOrFail();
    }
}
