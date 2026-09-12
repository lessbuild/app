<?php

namespace App\Http\Controllers;

use App\Actions\Recipe\ReopenRecipeReportAction;
use App\Actions\Recipe\ReopenRecipeReportsAction;
use App\Actions\Recipe\ResolveRecipeReportAction;
use App\Actions\Recipe\ResolveRecipeReportsAction;
use App\Actions\Recipe\ReviewRecipeReportUpdatesAction;
use App\Actions\Recipe\SubmitRecipeReportAction;
use App\Actions\Recipe\UpdateRecipeReportResolutionNoteAction;
use App\Actions\Recipe\WithdrawRecipeReportAction;
use App\Http\Requests\RecipeReportHistoryRequest;
use App\Http\Requests\RecipeReportInboxRequest;
use App\Http\Requests\RecipeReportResolutionRequest;
use App\Http\Requests\ReopenRecipeReportsRequest;
use App\Http\Requests\ResolveRecipeReportsRequest;
use App\Http\Requests\StoreRecipeReportRequest;
use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Services\RecipeReportHistoryExporter;
use App\Services\RecipeReportInboxExporter;
use App\Services\RecipeReportQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipeReportsController extends Controller
{
    public function __construct(
        private readonly RecipeReportHistoryExporter $historyExporter,
        private readonly RecipeReportInboxExporter $inboxExporter,
        private readonly RecipeReportQuery $reportQuery,
        private readonly ReviewRecipeReportUpdatesAction $reviewUpdates,
    ) {}

    /**
     * Render the request user's filtered recipe reports with pagination, availability counts, and unread report updates.
     */
    public function mine(RecipeReportHistoryRequest $request): View
    {
        $filters = $request->filters();
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
        $reviewed = $this->reviewUpdates->handle($request->user());

        return back()->with('status', $reviewed > 0
            ? trans_choice(':count report update was marked as reviewed.|:count report updates were marked as reviewed.', $reviewed, ['count' => $reviewed])
            : __('There are no unread report updates.'));
    }

    /**
     * Stream the reporter's filtered submissions, recipe availability, and resolution notes as private CSV.
     */
    public function exportMine(RecipeReportHistoryRequest $request): StreamedResponse
    {
        $filters = $request->filters();

        return $this->historyExporter->stream($request->user(), $filters);
    }

    /**
     * @param  Request  $request  The authenticated reporter's request.
     * @param  RecipeReport  $report  The implicitly bound report, including unpublished recipe history.
     * @return View The report status after verifying ownership.
     */
    public function status(Request $request, RecipeReport $report): View
    {
        $this->authorize('view', $report);
        $report->load('recipe:id,user_id,name,category,is_published,published_at');

        return view('scenes.gallery.report-status', [
            'report' => $report,
            'unreadUpdate' => $this->reportQuery->unread($request->user(), [$report->id])->get($report->id),
        ]);
    }

    /**
     * Render filtered reports about the request user's contributed recipes with review and recipe counts.
     */
    public function index(RecipeReportInboxRequest $request): View
    {
        $filters = $request->filters();
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
    public function export(RecipeReportInboxRequest $request): StreamedResponse
    {
        $filters = $request->filters();

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
        $this->authorize('report', $recipe);

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
        $this->authorize('review', [$report, $recipe]);
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
        $this->authorize('review', [$report, $recipe]);

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
    public function resolveMany(ResolveRecipeReportsRequest $request, ResolveRecipeReportsAction $resolveReports): RedirectResponse
    {
        $reportIds = collect($request->validated('reports'))->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $resolvedCount = $resolveReports->handle($request->user(), $reportIds);

        return back()->with('status', $resolvedCount > 0
            ? trans_choice(':count community report was marked as resolved.|:count community reports were marked as resolved.', $resolvedCount, ['count' => $resolvedCount])
            : __('The selected community reports were already resolved.'));
    }

    /**
     * Authorize the contributor and recipe/report relationship, reopen a resolved report under locks, and redirect back.
     */
    public function reopen(Request $request, Recipe $recipe, RecipeReport $report, ReopenRecipeReportAction $reopenReport): RedirectResponse
    {
        $this->authorize('review', [$report, $recipe]);

        $reopenReport->handle($recipe, $report, $request->user());

        return back()->with('status', __('The community report was reopened.'));
    }

    /**
     * Validate at most 20 distinct contributor report IDs and atomically reopen those currently resolved.
     *
     * @return RedirectResponse The reopened count; any missing or foreign report aborts the operation with 404.
     */
    public function reopenMany(ReopenRecipeReportsRequest $request, ReopenRecipeReportsAction $reopenReports): RedirectResponse
    {
        $reportIds = collect($request->validated('reports'))->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $reopenedCount = $reopenReports->handle($request->user(), $reportIds);

        return back()->with('status', $reopenedCount > 0
            ? trans_choice(':count community report was reopened.|:count community reports were reopened.', $reopenedCount, ['count' => $reopenedCount])
            : __('The selected community reports were already open.'));
    }

    /**
     * Withdraw the request user's report for the bound recipe under a lock and remove its notifications before redirecting back.
     */
    public function destroy(Request $request, Recipe $recipe, WithdrawRecipeReportAction $withdrawReport): RedirectResponse
    {
        $withdrawReport->handle($recipe, $request->user());

        return back()->with('status', __('Your gallery report was withdrawn.'));
    }
}
