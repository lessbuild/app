<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivityIndexRequest;
use App\Models\Event;
use App\Services\ActivityExporter;
use App\Services\ActivityQuery;
use App\Services\Entitlements;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityController extends Controller
{
    /**
     * Use workspace audit entitlements to control activity export availability.
     */
    public function __construct(
        private readonly ActivityQuery $activity,
        private readonly ActivityExporter $exporter,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Render the request user's activity with normalized search, category, date filters, and aggregate counts.
     */
    public function __invoke(ActivityIndexRequest $request): View
    {
        $filters = $request->filters();
        $user = $request->user();
        $fragment = $request->string('fragment')->toString();
        $isFragment = in_array($fragment, ['account-audit', 'workspace-activity'], true);
        $paginationQuery = array_filter($filters, fn ($value) => $value !== null);
        if ($isFragment) {
            $paginationQuery['fragment'] = $fragment;
        }

        $events = $this->activity->for($user, $filters)
            ->with('parentable')
            ->latest()
            ->paginate(25)
            ->appends($paginationQuery);
        $auditAvailable = $this->entitlements->allows($request->user()->currentOrganization, 'audit');
        $viewData = [
            'events' => $events,
            'filters' => $filters,
            'metrics' => $this->activity->metrics($user, $filters),
            'categories' => Event::CATEGORIES,
            'auditAvailable' => $auditAvailable,
        ];

        if ($isFragment) {
            if ($fragment === 'workspace-activity') {
                return view('components.activity.history-content', [
                    ...$viewData,
                    'fullPageUrl' => route('activity.index', array_filter($filters, fn ($value) => $value !== null)),
                ]);
            }

            return view('components.activity.audit-content', [
                ...$viewData,
                'fullPageUrl' => route('activity.index', array_filter($filters, fn ($value) => $value !== null)),
            ]);
        }

        return view('activity.index', $viewData);
    }

    /**
     * Require the audit entitlement and stream filtered user activity as a private, spreadsheet-safe CSV.
     */
    public function export(ActivityIndexRequest $request): StreamedResponse
    {
        $this->entitlements->enforce($request->user()->currentOrganization, 'audit');

        return $this->exporter->stream($request->user(), $request->filters());
    }
}
